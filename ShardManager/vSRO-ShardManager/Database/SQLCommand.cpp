#include "SQLCommand.h"
#include "../Utils/BSObj.h"
#include <cwchar>

SQLCommand::SQLCommand()
	: m_StmtHandle(SQL_NULL_HSTMT),
	  m_IsOpen(false)
{
}
SQLCommand::~SQLCommand()
{
	Close();
}

bool SQLCommand::IsOpen() const
{
	return m_IsOpen;
}

bool SQLCommand::Open(const SQLConnection& Connection)
{
	Close();
	if (!Connection.IsOpen())
		return false;

	SQLRETURN result;
	result = SQLAllocHandle(SQL_HANDLE_STMT, SQLConnection::GetConnectionHandle(Connection), &m_StmtHandle);
	if (!SQL_SUCCEEDED(result))
	{
		SQLConnection::ShowError(SQL_HANDLE_STMT, m_StmtHandle, result);
		m_StmtHandle = SQL_NULL_HSTMT;
		return false;
	}
	SQLSetStmtAttr(m_StmtHandle, SQL_ATTR_QUERY_TIMEOUT, (SQLPOINTER)30, 0);
	m_IsOpen = true;
	return true;
}
bool SQLCommand::ExecuteQuery(SQLWCHAR* CommandText)
{
	if (!m_IsOpen || m_StmtHandle == SQL_NULL_HSTMT || CommandText == NULL)
		return false;

	Clear();
	SQLRETURN result = SQLExecDirect(m_StmtHandle, CommandText, SQL_NTS);
	if (!SQL_SUCCEEDED(result))
	{
		BS_ERROR("ODBC query execution failed");
		SQLConnection::ShowError(SQL_HANDLE_STMT, m_StmtHandle, result);
		return false;
	}
	return true;
}
void SQLCommand::Clear()
{
	if (m_StmtHandle != SQL_NULL_HSTMT)
		SQLCloseCursor(m_StmtHandle);
}
void SQLCommand::Close()
{
	if (m_StmtHandle != SQL_NULL_HSTMT)
	{
		SQLCloseCursor(m_StmtHandle);
		SQLFreeHandle(SQL_HANDLE_STMT, m_StmtHandle);
	}
	m_StmtHandle = SQL_NULL_HSTMT;
	m_IsOpen = false;
}
bool SQLCommand::FetchData()
{
    return FetchDataResult() == SQL_FETCH_ROW;
}
SQL_FETCH_RESULT SQLCommand::FetchDataResult()
{
    if (!m_IsOpen || m_StmtHandle == SQL_NULL_HSTMT)
        return SQL_FETCH_ERROR;
    const SQLRETURN result = SQLFetch(m_StmtHandle);
    if (result == SQL_NO_DATA)
        return SQL_FETCH_NO_DATA;
    if (!SQL_SUCCEEDED(result))
    {
        SQLConnection::ShowError(SQL_HANDLE_STMT, m_StmtHandle, result);
        return SQL_FETCH_ERROR;
    }
    return SQL_FETCH_ROW;
}
bool SQLCommand::GetData(SQLUSMALLINT ColumnNumber, SQLSMALLINT TargetType, SQLPOINTER TargetValue, SQLINTEGER BufferLength, SQLINTEGER* StrLen_or_IndPtr)
{
	SQLRETURN result = SQLGetData(m_StmtHandle, ColumnNumber, TargetType, TargetValue, BufferLength, StrLen_or_IndPtr);
	if (result != SQL_SUCCESS)
	{
		BS_ERROR("ODBC column read failed (column=%u)", static_cast<unsigned>(ColumnNumber));
		SQLConnection::ShowError(SQL_HANDLE_STMT, m_StmtHandle, result);
		return false;
	}
	return true;
}

SQLHANDLE SQLCommand::GetStmtHandle()
{
	return m_StmtHandle;
}

void SQLCommand::ReleaseStmtHandle(SQLHANDLE hStmt)
{
	if (hStmt == m_StmtHandle)
	{
		Close();
		return;
	}
	if (hStmt != SQL_NULL_HSTMT)
		SQLFreeHandle(SQL_HANDLE_STMT, hStmt);
}
