#pragma once
#include <windows.h>
#include <iostream>
#include <vector>
#include <sstream>
#include <list>
#include <algorithm>
#include <assert.h>
#include <sstream>
#include <map>
#include <sql.h>
#include <sqlext.h>
#include <sqltypes.h>
#include <queue>
#include <deque>
#include <math.h>
#include <SqlConnection/sqlCon.h>

class CGItem;
class CAutoCriticalSection;

#define __LST_TIMED_ITEM_PLUS_APPLIED		std::list<long long>
#define __LST_TIMED_ITEM_PLUS_APPLIED_IT	__LST_TIMED_ITEM_PLUS_APPLIED::iterator

class CCustomTimedJobManager
{
private:
	static void ConsumeTimedItemPlusRecords();

	static DWORD WINAPI ConsumeThreadWorker(LPVOID lpParam);
public:
	static void Setup();
	static void ConsumeTimedItemPlusRecordsDevill();
	static void CreateConsumeThread();
};