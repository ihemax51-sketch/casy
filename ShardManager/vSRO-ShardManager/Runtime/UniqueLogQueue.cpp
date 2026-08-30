#include "UniqueLogQueue.h"

#include <Windows.h>
#include <deque>
#include <sstream>
#include "../Database/SQLConnection.h"
#include "../Database/SQLCommand.h"
#include "../Utils/BSObj.h"

namespace
{
    const size_t MAX_PENDING_EVENTS = 4096;

    struct UniqueEvent
    {
        bool killed;
        unsigned long refObjectId;
        std::string killerName;
        unsigned retryCount;
    };

    CRITICAL_SECTION s_lock;
    volatile LONG s_lockInitialized = 0;
    volatile LONG s_running = 0;
    HANDLE s_event = NULL;
    HANDLE s_thread = NULL;
    std::wstring s_connectionString;
    std::deque<UniqueEvent> s_queue;

    void EnsureLock()
    {
        if (InterlockedCompareExchange(&s_lockInitialized, 1, 0) == 0)
            InitializeCriticalSection(&s_lock);
    }

    std::wstring EscapeSqlText(const std::string& text)
    {
        std::wstring result;
        result.reserve(text.size() + 8);
        for (std::string::const_iterator it = text.begin(); it != text.end(); ++it)
        {
            const unsigned char value = static_cast<unsigned char>(*it);
            if (value == '\'')
                result.append(L"''");
            else if (value >= 0x20 && value < 0x7F)
                result.push_back(static_cast<wchar_t>(value));
        }
        return result;
    }

    bool TryPop(UniqueEvent& value)
    {
        EnterCriticalSection(&s_lock);
        if (s_queue.empty())
        {
            LeaveCriticalSection(&s_lock);
            return false;
        }
        value = s_queue.front();
        s_queue.pop_front();
        LeaveCriticalSection(&s_lock);
        return true;
    }

    void RetryOrDrop(const UniqueEvent& value)
    {
        if (value.retryCount >= 2)
        {
            BS_ERROR("Dropped a unique history event after repeated database failures");
            return;
        }

        UniqueEvent retry = value;
        ++retry.retryCount;
        EnterCriticalSection(&s_lock);
        s_queue.push_front(retry);
        LeaveCriticalSection(&s_lock);
    }

    DWORD WINAPI Worker(LPVOID)
    {
        SQLConnection connection;
        SQLCommand command;

        while (InterlockedCompareExchange(&s_running, 1, 1) == 1)
        {
            WaitForSingleObject(s_event, 5000);

            UniqueEvent value;
            while (InterlockedCompareExchange(&s_running, 1, 1) == 1 && TryPop(value))
            {
                if (!connection.IsOpen() &&
                    (!connection.Open(const_cast<SQLWCHAR*>(s_connectionString.c_str())) ||
                     !command.Open(connection)))
                {
                    RetryOrDrop(value);
                    Sleep(2000);
                    break;
                }

                std::wstringstream query;
                if (value.killed)
                {
                    query << L"EXEC [KMTGuard].[dbo].[Hook_UniqueKill] "
                          << value.refObjectId << L", N'" << EscapeSqlText(value.killerName) << L"'";
                }
                else
                {
                    query << L"EXEC [KMTGuard].[dbo].[Hook_UniqueSpawn] " << value.refObjectId;
                }

                if (!command.ExecuteQuery(const_cast<SQLWCHAR*>(query.str().c_str())))
                {
                    command.Close();
                    connection.Close();
                    RetryOrDrop(value);
                    Sleep(1000);
                    break;
                }
                command.Clear();
            }
        }

        command.Close();
        connection.Close();
        return 0;
    }

    bool Enqueue(const UniqueEvent& value)
    {
        if (InterlockedCompareExchange(&s_running, 1, 1) != 1)
            return false;

        EnterCriticalSection(&s_lock);
        if (s_queue.size() >= MAX_PENDING_EVENTS)
        {
            LeaveCriticalSection(&s_lock);
            BS_WARNING("Unique history queue is full; event rejected");
            return false;
        }
        s_queue.push_back(value);
        LeaveCriticalSection(&s_lock);
        SetEvent(s_event);
        return true;
    }
}

bool UniqueLogQueue::Initialize(const std::wstring& connectionString)
{
    if (connectionString.empty())
        return false;
    if (InterlockedCompareExchange(&s_running, 1, 0) != 0)
        return true;

    EnsureLock();
    s_connectionString = connectionString;
    s_event = CreateEvent(NULL, FALSE, FALSE, NULL);
    if (s_event == NULL)
    {
        InterlockedExchange(&s_running, 0);
        return false;
    }

    s_thread = CreateThread(NULL, 0, Worker, NULL, 0, NULL);
    if (s_thread == NULL)
    {
        CloseHandle(s_event);
        s_event = NULL;
        InterlockedExchange(&s_running, 0);
        return false;
    }

    BS_INFO("Unique history worker started");
    return true;
}

void UniqueLogQueue::Shutdown()
{
    if (InterlockedExchange(&s_running, 0) == 0)
        return;
    if (s_event != NULL)
        SetEvent(s_event);
    if (s_thread != NULL)
    {
        WaitForSingleObject(s_thread, 5000);
        CloseHandle(s_thread);
        s_thread = NULL;
    }
    if (s_event != NULL)
    {
        CloseHandle(s_event);
        s_event = NULL;
    }
    EnterCriticalSection(&s_lock);
    s_queue.clear();
    LeaveCriticalSection(&s_lock);
    s_connectionString.clear();
}

bool UniqueLogQueue::EnqueueSpawn(unsigned long refObjectId)
{
    UniqueEvent value = { false, refObjectId, std::string(), 0 };
    return Enqueue(value);
}

bool UniqueLogQueue::EnqueueKill(unsigned long refObjectId, const std::string& killerName)
{
    if (killerName.empty() || killerName.size() > 64)
        return false;
    UniqueEvent value = { true, refObjectId, killerName, 0 };
    return Enqueue(value);
}
