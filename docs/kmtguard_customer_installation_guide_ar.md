# ابدأ من هنا: دليل تثبيت KMTGuard للعميل

آخر تحديث: 2026-07-26

هذا الدليل مخصص لأول تركيب على سيرفر vSRO 1.188. نفّذ الخطوات بالترتيب، ولا
تشغّل اللاعبين على الفلتر قبل نجاح قائمة الاختبار في آخر الدليل.

> مهم: لا ترسل `Settings.json` أو `KMTGuard-Addon.ini` أو صورًا منهما لأي شخص؛
> هذه الملفات تحتوي بيانات اتصال SQL. لا تعدّل `KMTGuard-License.txt` ولا
> `PACKAGE-INFO.txt` ولا أسماء ملفات KMTGuard المحمية.

## 1. محتويات الباكدج

| المجلد | استخدامه |
|---|---|
| `Filter` | لوحة التحكم `KMTGuard.exe` وخدمات Agent وDownload وGateway. |
| `DLL` | ملفات DLL الخاصة بالكلاينت. |
| `Media` | ملفات الميديا التي تدمج في الـMedia.pk2 مع الحفاظ على المسارات. |
| `Client-import` | سطور يدوية مطلوبة لبعض ملفات الكلاينت. |
| `ServerAddons\GameServer` | إضافة `SR_GameServer.exe`. |
| `ServerAddons\ShardManager` | إضافة `SR_ShardManager.exe`. |

الـZIP لا ينشئ قاعدة KMTGuard من الصفر. يجب أن تكون استلمت قاعدة KMTGuard
الأساسية أو Backup معتمدًا من المورّد. لو لم تستلمها، توقّف واطلبها قبل تشغيل
الفلتر. لا تستخدم Backup من عميل آخر.

## 2. المطلوب قبل التركيب

1. Windows Server x64، وSQL Server وSSMS يعملان بصورة طبيعية.
2. ثبّت **.NET 8 Desktop Runtime x64**.
3. ثبّت **Microsoft Visual C++ Redistributable 2015-2022 x86** لإضافات
   السيرفر والكلاينت.
4. اعرف الـPublic IP المرخّص للسيرفر، وأسماء قواعد Account وShard وShardLog.
5. شغّل ملفات Silkroad الأصلية وسجّل البورتات الحقيقية للـGateway والـDownload
   والـAgent. يمكن تشغيل `Detect-Silkroad-Real-Ports.cmd` كمسؤول بعد تشغيل
   الموديولات.
6. خذ Backup كامل من قواعد SQL ومن:
   `SR_GameServer.exe` و`SR_ShardManager.exe` والكلاينت وMedia.pk2.

## 3. تجهيز SQL

1. Restore لقاعدة KMTGuard المعتمدة باسم `KMTGuard`.
2. لو استلمت قاعدة `Events` أو سكربت إنشاء خاص بها، ثبّتها حسب تعليمات المورّد.
3. تأكد أن قواعد Silkroad موجودة. الأسماء الشائعة:

   - Account: `SRO_VT_ACCOUNT`
   - Shard: `SRO_VT_SHARD`
   - Log: `SRO_VT_SHARDLOG` أو `SRO_VT_LOG`
   - Filter: `KMTGuard`
   - Events: `Events`

4. استخدم SQL Login مخصصًا لـKMTGuard إن أمكن. الحساب يحتاج قراءة وكتابة
   وتنفيذ Procedures في `KMTGuard`، والوصول المطلوب إلى Account وShard وLog
   وEvents. أثناء أول تركيب فقط يمكن استخدام حساب SQL إداري معتمد، ثم استبداله
   بحساب محدود بعد اختبار كل الوظائف.
5. لو SQL على نفس الجهاز استخدم `127.0.0.1`. لا تفتح بورت SQL للإنترنت. لو SQL
   على جهاز آخر، افتحه فقط بين عنواني الجهازين.

اختبار سريع من SSMS:

```sql
SELECT DB_NAME() AS CurrentDatabase;
SELECT TOP (10) SettingName, Value
FROM KMTGuard.dbo.System_Settings
ORDER BY SettingName;

SELECT ServiceId, Name, ServerType, RemoteIP, RemotePort, BindIP, BindPort, AutoStart
FROM KMTGuard.dbo.System_ProxyServices
ORDER BY ServiceId;
```

يجب أن يرجع الاستعلامان بيانات. عدم وجود `System_Settings` أو
`System_ProxyServices` يعني أن قاعدة KMTGuard غير مكتملة.

## 4. أول تشغيل للـDashboard

1. انقل مجلد الباكدج لمسار ثابت، مثل `D:\KMTGuard`. لا تشغّله من داخل الـZIP.
2. شغّل `Filter\KMTGuard.exe` كمسؤول.
3. افتح `Connection`.
4. املأ الحقول كالتالي:

| الحقل | القيمة |
|---|---|
| `SQL host` | `127.0.0.1` لو SQL على نفس السيرفر، وإلا IP جهاز SQL الداخلي. |
| `Port` | بورت SQL الفعلي؛ غالبًا `1433`. اتركه فارغًا فقط لو الاتصال الافتراضي يعمل. |
| `Proxy database` | `KMTGuard`. |
| `Username` | SQL Login الخاص بالعميل. |
| `Password` | كلمة مرور SQL الخاصة بالعميل. |
| `Allowed server IP` | الـPublic IP الموجود في الرخصة. لا تغيّره في رخصة IP إلا بعد الرجوع للمورّد. |
| `Maximum pool` | اترك قيمة الباكدج الافتراضية. |
| `Minimum pool` | اترك قيمة الباكدج الافتراضية. |
| `Connection lifetime` | اترك قيمة الباكدج الافتراضية. |

5. اضغط `Save Settings.json` ثم `Test SQL`.
6. يجب أن تظهر قاعدة البيانات Connected في `Dashboard`.
7. افتح `Filter Settings` ثم قسم `Database` واضبط الأسماء الحقيقية:

   - `AccountDB`
   - `ShardDB`
   - `LogDB`

8. اضغط `Save Section`. لا تغيّر باقي الحمايات والـlimits في أول تشغيل. أي
   إعداد Database أو Ports يحتاج Restart للخدمات بعد حفظه.

## 5. ضبط بورتات الفلتر

من `Connection` انزل إلى `Proxy services` واضغط `Load`. ستظهر خدمة Agent
وDownload وGateway.

| الخدمة | `IP` و`Real Port` | `Listen IP` | `Fake Port` المقترح |
|---|---|---|---:|
| Download | عنوان وبورت `DownloadServer.exe` الحقيقيان | `0.0.0.0` | `10001` |
| Gateway | عنوان وبورت `GatewayServer.exe` الحقيقيان | `0.0.0.0` | `10002` |
| Agent | عنوان وبورت `AgentServer.exe` الحقيقيان | `0.0.0.0` | `10003` |

- `Real Port` هو بورت موديول Silkroad الأصلي، وليس بورت الفلتر.
- `Fake Port` هو البورت العام الذي يسمع عليه KMTGuard.
- استخدم `127.0.0.1` في خانة `IP` لو الموديول الحقيقي يقبل اتصالًا محليًا.
  غير ذلك استخدم عنوان الشبكة الموجود فعلًا على السيرفر.
- `0.0.0.0` في `Listen IP` مناسب للسيرفرات خلف NAT؛ KMTGuard سيرسل للاعب
  الـPublic IP الموجود في `Allowed server IP`.
- فعّل `AutoStart` للخدمات الثلاث، ثم اضغط `Save`.
- لو لديك أكثر من AgentServer، اضبط صفًا مستقلًا لكل Agent بالقيم الحقيقية.

### مثال فقط

الأرقام التالية مثال شائع وليست قيمًا إجبارية:

| الخدمة | Real Port | Fake Port |
|---|---:|---:|
| Download | `15881` | `10001` |
| Gateway | `29000` | `10002` |
| Agent | `15884` | `10003` |

لا تنسخ الـReal Ports من المثال. استخرجها من سيرفرك.

## 6. Firewall واللانشر

افتح TCP للبورتات العامة التي اخترتها، في Windows Firewall وفي Firewall شركة
الاستضافة:

```powershell
New-NetFirewallRule -DisplayName "KMTGuard Public Proxies" `
  -Direction Inbound -Action Allow -Protocol TCP `
  -LocalPort 10001,10002,10003
```

- وجّه الـLauncher/DivisionInfo إلى:
  `PUBLIC_IP:10002`، أي Fake Gateway، وليس البورت الحقيقي للـGateway.
- لا تنشر البورتات الحقيقية `29000/15881/15884` للعامة. اسمح بها فقط حسب
  احتياج موديولات Silkroad والشبكة الداخلية.
- لا تفتح `1433` للعالم.
- من جهاز خارج السيرفر شغّل:

```text
Test-KMTGuard-Public-Ports.cmd PUBLIC_IP
```

المطلوب أن تكون بورتات Gateway وDownload وAgent العامة `OPEN`.

## 7. تركيب إضافات GameServer وShardManager

نفّذ هذه الخطوة والسيرفر متوقف:

> لا يوجد ملف ترخيص منفصل داخل مجلدي GameServer وShardManager، وهذا مقصود.
> ضع `KMTGuard-License.txt` داخل مجلد `Filter`، ثم شغّل `KMTGuard.exe` مرة
> واحدة كمسؤول حتى ينجح التفعيل. بعد ذلك تستخدم إضافتا GameServer وShardManager
> الترخيص المشترك المفعّل تلقائيًا. لا تنسخ ملف الترخيص الخام قبل التفعيل بجانب
> ملفات السيرفر، لأنه لا يحتوي بعد على تصريح التشغيل الموقّع.

1. انسخ محتويات `ServerAddons\GameServer` بجانب `SR_GameServer.exe`.
2. افتح ملف `KMTGuard-Addon.ini` الموجود بجانبه، واكتب اتصال SQL الخاص بالعميل:

```ini
[Database]
SQLSERVER=127.0.0.1
LoginId=KMTGuardUser
Password=CHANGE_ME
```

3. انسخ محتويات `ServerAddons\ShardManager` بجانب `SR_ShardManager.exe`، واضبط
   ملف `KMTGuard-Addon.ini` بنفس الطريقة.
4. حمّل كل DLL في الموديول المطابق باستخدام طريقة الـimport/injection المعتمدة
   عندك:

   - `KMTGuard_GameServer.dll` داخل `SR_GameServer.exe`
   - `KMTGuard_ShardManager.dll` داخل `SR_ShardManager.exe`

5. لا تحمّل DLL في موديول مختلف، ولا تستخدم DLL من باكدج عميل آخر.
6. لا تنشر ملفي INI لأنهما يحتويان كلمة مرور SQL.

لو لم تكن تستخدم Loader/Import لإضافات السيرفر من قبل، لا تعدّل ملفات EXE
بالتجربة؛ اطلب تنفيذ خطوة الحقن من الفني المسؤول مع الاحتفاظ بالنسخة الأصلية.

## 8. تركيب DLL وميديا الكلاينت

1. انسخ ملفات مجلد `DLL` بجانب `sro_client.exe` أو إلى مسار الـloader المعتمد
   في كلاينتك.
2. تأكد أن `KMTGuardKit.dll` يتم تحميله فعلًا عند تشغيل الكلاينت. مجرد نسخه
   بجانب EXE لا يكفي إذا كان الكلاينت لا يستخدم Loader أو Import له.
3. ادمج محتويات مجلد `Media` داخل Media.pk2 مع الحفاظ على نفس المجلدات
   والمسارات. لا تنقل الملفات إلى Root الـPK2.
4. نفّذ تعليمات الملفات الموجودة في `Client-import` للملفات النصية المطلوبة.
5. اعمل Patch جديد للانشر بعد تغيير عنوان Gateway إلى Fake Gateway Port.
6. اختبر نسخة كلاينت نظيفة قبل توزيعها على اللاعبين.

## 9. ترتيب التشغيل

1. شغّل خدمات Silkroad الخلفية المطلوبة حتى تكون البورتات الحقيقية Listening.
2. من `Filter\KMTGuard.exe` اضغط `Start All`.
3. KMTGuard يشغّل خدماته بالترتيب: Agent ثم Download ثم Gateway.
4. Dashboard يجب أن يعرض `3 / 3`.
5. بعد ذلك شغّل `SR_ShardManager.exe` و`SR_GameServer.exe` بالـDLLs المعتمدة
   حسب ترتيب تشغيل سيرفرك المعتاد.
6. شغّل لانشرًا من جهاز خارجي واختبر التحديث، تسجيل الدخول، اختيار الشخصية،
   والانتقال إلى Agent.

إغلاق نافذة Dashboard لا يوقف خدمات KMTGuard. استخدم `Stop All` لإيقافها.

## 10. اختبار التسليم قبل فتح السيرفر

- [ ] `Test SQL` ناجح.
- [ ] `AccountDB` و`ShardDB` و`LogDB` أسماؤها صحيحة.
- [ ] خدمات Dashboard تعرض `3 / 3`.
- [ ] Logs لا تحتوي `License`, `SQL login failed`, `failed to bind` أو
      `connection refused`.
- [ ] `10001` و`10002` و`10003` مفتوحة من جهاز خارجي.
- [ ] اللانشر يتصل بـFake Gateway وليس Real Gateway.
- [ ] التحديث/Start يعمل عن طريق Download filter.
- [ ] تسجيل الدخول يحوّل إلى Agent filter بنجاح.
- [ ] لاعب تجريبي يدخل العالم وتظهر واجهات KMTGuard.
- [ ] GameServer وShardManager حمّلا DLL الصحيحة بدون Crash.
- [ ] تم أخذ Backup نهائي من SQL والملفات بعد نجاح التركيب.

## 11. أشهر الأعطال

| العطل | المراجعة |
|---|---|
| `Test SQL` يفشل | SQL host/port، SQL Authentication، اسم KMTGuard، وصلاحيات المستخدم. |
| `IP verification failed` | `Allowed server IP` لا يطابق الـPublic IP المرخّص. |
| `failed to bind` | Fake Port مستخدم أو `Listen IP` غير موجود على الجهاز. استخدم `0.0.0.0` أو غيّر البورت. |
| اللانشر يفتح لكن Start يفشل | Fake Download مغلق أو Redirect/Media version غير صحيح. |
| Login ينجح ثم يفصل | Fake Agent مغلق أو Real Agent خطأ. |
| Dashboard يعرض أقل من `3 / 3` | افتح Logs الخاصة بالخدمة التي فشلت وراجع SQL والبورت الخاص بها. |
| الواجهات لا تظهر | DLL لم تُحمّل، أو ميديا ناقصة/في مسار خاطئ. |
| GameServer/ShardManager يعرض خطأ ترخيص | شغّل `Filter\KMTGuard.exe` أولًا كمسؤول لإكمال التفعيل المشترك، وتأكد أن DLL وملف الترخيص صادران لنفس باكدج العميل. |
| GameServer/ShardManager لا يفتح | DLL غير محمّلة بالطريقة الصحيحة، ملف INI ناقص، أو VC++ x86 غير مثبت. |

ملفات اللوج داخل:

```text
Filter\logs\kmtguard-agent-YYYYMMDD.log
Filter\logs\kmtguard-download-YYYYMMDD.log
Filter\logs\kmtguard-gateway-YYYYMMDD.log
```

عند طلب الدعم أرسل آخر جزء من اللوج بعد إخفاء كلمات المرور والـHWID والـtokens.
