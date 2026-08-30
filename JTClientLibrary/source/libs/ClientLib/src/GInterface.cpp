#include <CustomInterface/IFCustomMessageBox.h>
#include <CustomData/CustomSettingManager.h>
#include <SecondBar/IFExtQuickSlotCustom.h>
#include <Social/IFSocial.h>
#include <Menu/IFMenu.h>
#include <Menu/IFAchievements.h>
#include <Menu/IFEventSchedule.h>
#include <Menu/IFUniqueHistory.h>
#include <Menu/IFDynamicRanking.h>
#include <Menu/IFGrantName.h>
#include <Menu/IFChangelog.h>
#include <Guides/IFChest.h>
#include <Menu/IFIconManager.h>
#include <Menu/IFEventRegister.h>
#include <Menu/IFTitleManager.h>
#include <NewItemMall/IFVAvatarMall.h>
#include <NewItemMall/IFVSelectMall.h>
#include <NewItemMall/IFVAvatarMall.h>
#include <NewItemMall/IFVItemMall.h>
#include <NewItemMall/IFVItemMallBuyItem.h>
#include <DailyLogin/IFDailyLogin.h>
#include <CustomInterface/IFSavedLocation.h>
#include <CustomInterface/IFDropLogWnd.h>
#include <CustomInterface/IFDropLogsWnd.h>
#include <CustomData/CustomCICPlayer.h>
#include "SkillAutomationController.h"
#include <CustomInterface/IFMovePartyMember.h>
#include <NewItemMall/IFVAvatarMallBuyItemList.h>
#include <Web/IFWeb.h>
#include <Data/RefShopdata.h>
#include <BSLib/multibyte.h>
#include <Macro/IFMacro.h>
#include <Macro/IFMacroMenu.h>
#include <MacroAlchemy/IFAlchemyMacro.h>
#include <SRIFLib/NIFEnchantWnd.h>
#include <CustomData/CustomDataManager.h>
#include <SRIFLib/NIFAlchemyWnd.h>
#include <LockItems/IFItemUnlocker.h>
#include <LockItems/IFItemLocker.h>
#include <LockItems/IFNewMsgBox.h>
#include <CustomInterface/IFLuckySpinWnd.h>
#include <CustomInterface/IFSpecialOffersWnd.h>
#include <CustomInterface/IFKillerAnimationWnd.h>
#include <CustomInterface/IFPvpChallengeWnd.h>
#include <CustomInterface/IFTradeCaptchaWnd.h>
#include <CustomInterface/IFOfflineStall.h>
#include <SecondPW/IFSecondaryPassword.h>
#include <GFXMainFrame/Controler.h>
#include <ExtraUI/IFItemTranslationWnd.h>
#include <ExtraUI/IFSettings.h>
#include "GInterface.h"

#include "IFMenuGuide.h"
#include "IFNotify.h"
#include "IFChatViewer.h"
#include "IFNotify.h"

#include "Game.h"
#include "IFItemMall.h"
#include "GlobalDataManager.h"
#include "IFItemMallShopSlot.h"
#include "InterfaceNetSender.h"
#include "NIFAlchemySubWndType1.h"
#include "ICPlayer.h"

static bool IsMacroWorldReady()
{
    if (!m_Player ||
        !m_Player->FirstSpawn ||
        !g_pMyPlayerObj ||
        g_pMyPlayerObj->CHARACTER_STATUS == 0 ||
        g_pMyPlayerObj->GetMaxHp() == 0 ||
        g_pMyPlayerObj->GetMaxMp() == 0 ||
        !g_pCGInterface ||
        !g_pCGInterface->GetMainPopup() ||
        !g_pCGInterface->GetMainPopup()->GetInventory() ||
        !g_pCGInterface->GetMainPopup()->GetEquipment())
    {
        return false;
    }

    return g_pMyPlayerObj->CHARACTER_STATUS == Dead ||
        g_pMyPlayerObj->GetCurrentHp() != 0;
}

CIFDropLogWnd* CGInterface::EnsurePickInventoryWindow()
{
    CIFDropLogWnd* window = GetGuiFromList<CIFDropLogWnd>(DROP_LOG_WINDOW_ID);
    if (window)
        return window;

    const CGfxRuntimeClass& runtimeClass = GFX_RUNTIME_CLASS(CIFDropLogWnd);
    if (!runtimeClass.m_pfnCreateObject)
        return NULL;

    RECT dropLogRect = {300, 200, 370, 415};
    CGWnd* createdWindow = CreateInstance(
        this, runtimeClass, dropLogRect, DROP_LOG_WINDOW_ID, 0);
    if (!createdWindow)
        return NULL;

    window = GetGuiFromList<CIFDropLogWnd>(DROP_LOG_WINDOW_ID);
    if (window != createdWindow)
        return NULL;

    return window;
}

bool CGInterface::TogglePickInventoryWindow()
{
    CIFDropLogWnd* pDropLogWnd = EnsurePickInventoryWindow();
    if (!pDropLogWnd)
        return false;

    static DWORD lastToggleTick = 0;
    const DWORD now = GetTickCount();
    if (now - lastToggleTick < 150)
        return true;

    lastToggleTick = now;
    const bool opening = !pDropLogWnd->IsVisible();
    if (opening) {
        pDropLogWnd->OpenGroundDropList();
        pDropLogWnd->MoveGWnd(300, 200);
        pDropLogWnd->ShowGWnd(true);
        pDropLogWnd->SetClickable(true);
        pDropLogWnd->BringToFront();
    } else {
        pDropLogWnd->ShowGWnd(false);
    }

    CGEffSoundBody::get()->PlaySound(opening ? L"snd_window_open" : L"snd_window_close");
    return true;
}

bool CGInterface::OnCreateIMPL(long ln) {

    BeforeOnCreate();

    bool b = reinterpret_cast<bool (__thiscall *)(CGInterface *, long)>(0x0078c910)(this, ln);

    AfterOnCreate();

    return b;
}

void CGInterface::SuspendMacroAutomationForWorldTransition()
{
    g_SkillAutomationController.SuspendForWorldTransition();

    for (int timerId = HP_TIMER; timerId <= START_AUTO_POTION; ++timerId)
        KillTimer(timerId);

    KillTimer(START_PICK_PET_TIMER);
    KillTimer(START_AUTO_SCROLL_TIMER);
    KillTimer(START_PETT_BUFF_TIMER_1);

    if (m_Player)
    {
        m_Player->FirstSpawn = false;
        m_Player->PetSkillTimerRunning = false;
    }

    CIFMacroMenu* macroMenu = m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1);
    if (!macroMenu)
        return;

    if (macroMenu->AutoPotionSlot)
        macroMenu->AutoPotionSlot->StopAutomation();

    if (macroMenu->AutoHuntSlot)
    {
        macroMenu->AutoHuntSlot->AutoHuntTimerRunning = false;
        macroMenu->AutoHuntSlot->MacroAutoTownTimerRunning = false;
        macroMenu->AutoHuntSlot->MacroAutoInviteRunning = false;
        macroMenu->AutoHuntSlot->StartRegion.r = 0;
        macroMenu->AutoHuntSlot->StartPosition.x = 0.0f;
        macroMenu->AutoHuntSlot->StartPosition.y = 0.0f;
        macroMenu->AutoHuntSlot->StartPosition.z = 0.0f;
    }

    if (macroMenu->AutoSkillSlot)
    {
        macroMenu->AutoSkillSlot->AutoSkillTimerRunning = false;
        macroMenu->AutoSkillSlot->ResetSelectedTarget();
    }

    if (macroMenu->PickupFilterSlot)
    {
        macroMenu->PickupFilterSlot->PetPickTimerIsRunning = false;
        macroMenu->PickupFilterSlot->m_lastPickupRequestTime.clear();
    }

    if (macroMenu->AutoScrollSlot)
        macroMenu->AutoScrollSlot->AutoScrollTimerRunning = false;
}

void CGInterface::OnTimerIMPL(int timerId) {
    if (timerId == SKILL_AUTOMATION_TIMER)
    {
        if (this != g_pCGInterface)
        {
            KillTimer(timerId);
            return;
        }

        KillTimer(timerId);
        g_SkillAutomationController.Tick();
        g_SkillAutomationController.ScheduleNextTick();
        return;
    }

    const bool isMacroTimer =
        (timerId >= HP_TIMER && timerId <= START_AUTO_POTION) ||
        timerId == START_PICK_PET_TIMER ||
        timerId == START_AUTO_SCROLL_TIMER;
    const bool isAutomationTimer =
        isMacroTimer || timerId == START_PETT_BUFF_TIMER_1;

    if (isAutomationTimer && this != g_pCGInterface)
    {
        KillTimer(timerId);
        return;
    }

    CIFMacroMenu* macroMenu = 0;
    if (isMacroTimer)
    {
        macroMenu = m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1);
        if (!macroMenu || !macroMenu->AutoPotionSlot || !macroMenu->AutoSkillSlot ||
            !macroMenu->AutoHuntSlot || !macroMenu->PickupFilterSlot ||
            !macroMenu->AutoScrollSlot)
        {
            KillTimer(timerId);
            return;
        }
    }

    if (isMacroTimer && !IsMacroWorldReady())
    {
        const bool isAutoPotionActionTimer =
            timerId >= HP_TIMER && timerId <= PET_RES_TIMER;
        if (isAutoPotionActionTimer)
        {
            macroMenu->AutoPotionSlot->StartAutomation();
            return;
        }

        if (timerId == START_BACK_TOWN ||
            timerId == STARTED_INVITE_PLAYER_PARTY)
        {
            macroMenu->AutoHuntSlot->StartAutoHunt();
            return;
        }

        if (timerId == START_AUTO_SKILL ||
            timerId == START_PICK_PET_TIMER ||
            timerId == START_AUTO_SCROLL_TIMER)
        {
            return;
        }
    }
    if (timerId == START_PETT_BUFF_TIMER_1 && !IsMacroWorldReady())
    {
        KillTimer(START_PETT_BUFF_TIMER_1);
        if (m_Player)
            m_Player->PetSkillTimerRunning = false;
        return;
    }
    if(timerId == HP_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterHP();
    }
    if(timerId == HP_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterHpPotion();
        this->KillTimer(HP_USINGTIMER);
    }
    if(timerId == MP_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterMP();
    }
    if(timerId == MP_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterMpPotion();
        this->KillTimer(MP_USINGTIMER);
    }
    if(timerId == VIGOR_TIMER_HP)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterVigorHP();
    }
    if(timerId == VIGOR_USINGTIMER_HP)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterVigorHpPotion();
        this->KillTimer(VIGOR_USINGTIMER_HP);
    }
    if(timerId == VIGOR_TIMER_MP)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterVigorMP();
    }
    if(timerId == VIGOR_USINGTIMER_MP)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterVigorMpPotion();
        this->KillTimer(VIGOR_USINGTIMER_MP);
    }

    if(timerId == PILL_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterPILL();

    }
    if(timerId == PILL_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterPILL();
        this->KillTimer(PILL_USINGTIMER);
    }

    if(timerId == PILL_TIMER_PURI)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckCharacterPILLPuri();

    }
    if(timerId == PILL_USINGTIMER_PURI)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterPILLPuri();
        this->KillTimer(PILL_USINGTIMER_PURI);
    }

    if(timerId == SPEED_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UseCharacterSpeed();
    }

    if(timerId == PET_HP_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckPetHP();

    }
    if(timerId == PET_HP_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UsePetHpPotion();
        this->KillTimer(PET_HP_USINGTIMER);
    }

    if(timerId == PET_HGP_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckPetHgp();

    }
    if(timerId == PET_HGP_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UsePetHgpPotion();
        this->KillTimer(PET_HGP_USINGTIMER);
    }

    if(timerId == PET_PILL_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckPetPILL();

    }
    if(timerId == PET_PILL_USINGTIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->UsePetPILL();
        this->KillTimer(PET_PILL_USINGTIMER);
    }

    if(timerId == PET_SUMMON_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckSummonedPet();
    }
    if(timerId == PET_RES_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->CheckDeadPet();
    }
    if(timerId == START_AUTO_SKILL)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoSkillSlot->StartAutoSkill();
    }

    if(timerId == STARTED_INVITE_PLAYER_PARTY)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoHuntSlot->InviteNearPartyMembers();

        if(g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoHuntSlot->MacroAutoInviteRunning)
        {
            g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoHuntSlot->MacroAutoInviteRunning = false;
            this->KillTimer(STARTED_INVITE_PLAYER_PARTY);
        }
    }
    if(timerId == START_BACK_TOWN)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoHuntSlot->BackTown();
    }
    if(timerId == START_AUTO_HUNT)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoHuntSlot->StartAutoHunt();
    }
    if(timerId == START_AUTO_POTION)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->StartAutomation();
    }
    if(timerId == START_PICK_PET_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->PickupFilterSlot->PickWithPet();
    }
    if(timerId == START_AUTO_SCROLL_TIMER)
    {
        g_pCGInterface->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoScrollSlot->AutoScrolling();
    }
    if(timerId == START_PETT_BUFF_TIMER_1)
    {
        UsePetSkill_1();
        if (m_Player->PetSkillTimerRunning) {
            m_Player->PetSkillTimerRunning = false;
            this->KillTimer(START_PETT_BUFF_TIMER_1);
        }
    }
    reinterpret_cast<void(__thiscall *)(CGInterface *, int)>(0x00789820)(this, timerId);
}
void CGInterface::UsePetSkill_1()
{
    if (!g_pMyPlayerObj) {
        return;
    }
    if (g_pMyPlayerObj->CHARACTER_STATUS == Dead ||
        g_pMyPlayerObj->CHARACTER_STATUS == Stall || g_pMyPlayerObj->Dead0Stay1Walking2Sit0SkillCast0emotion33Stall12817isridingpet == 17
        || g_pMyPlayerObj->CHARACTER_STATUS == 0)
    {
        return;
    }
    for(std::map<int, CCOSDataMgr::CosData*>::iterator it = g_pMyPlayerObj->CCOSDataMgr->CosList.begin();
        it != g_pMyPlayerObj->CCOSDataMgr->CosList.end(); ++it)
    {
        CICharactor *pUser = GetCharacterObjectByID_MAYBE(it->first);
        if (pUser != NULL) {
            static const CCharacterData *data = NULL;
            data = g_CGlobalDataManager->GetCharacter(pUser->GetCommonData()->RefObjectId);
            if(data)
            {
                std::n_wstring NameStr = pUser->GetCommonData()->NameStrID;
                if(m_CustomDataManager->m_RefFellowPetSystem.find(NameStr) != m_CustomDataManager->m_RefFellowPetSystem.end())
                {
                    if(m_Player->m_FellowSkillData.size() > 0)
                    {
                        if(data->GetData().Level >= m_CustomDataManager->m_RefFellowPetSystem[NameStr].Active_Level_1)
                        {
                            if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_1 > 0)
                            {
                                if(m_Player->m_FellowSkillData[0].Enable_Skill_1 == 1)
                                {
                                    int cooldown = g_pCGInterface->GetSkillCoolTimeManager()->FUN_009bba90(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_1);

                                    if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillType_1 == 0)
                                    {
                                        /// PET ICIN
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_1, (DWORD32)pUser)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 1;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }
                                    }
                                    else
                                    {
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_1, (DWORD32) g_pMyPlayerObj)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 1;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }

                                    }

                                }
                            }
                        }
                        if(data->GetData().Level >= m_CustomDataManager->m_RefFellowPetSystem[NameStr].Active_Level_2)
                        {
                            if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_2 > 0)
                            {
                                if(m_Player->m_FellowSkillData[0].Enable_Skill_2 == 1)
                                {
                                    int cooldown = g_pCGInterface->GetSkillCoolTimeManager()->FUN_009bba90(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_2);

                                    if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillType_2 == 0)
                                    {
                                        /// PET ICIN
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_2, (DWORD32)pUser)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 2;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }
                                    }
                                    else
                                    {
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_2, (DWORD32) g_pMyPlayerObj)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 2;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }

                                    }

                                }
                            }
                        }
                        if(data->GetData().Level >= m_CustomDataManager->m_RefFellowPetSystem[NameStr].Active_Level_3)
                        {
                            if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_3 > 0)
                            {
                                if(m_Player->m_FellowSkillData[0].Enable_Skill_3 == 1)
                                {
                                    int cooldown = g_pCGInterface->GetSkillCoolTimeManager()->FUN_009bba90(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_3);

                                    if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillType_3 == 0)
                                    {
                                        /// PET ICIN
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_3, (DWORD32)pUser)) {
                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 3;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }
                                    }
                                    else
                                    {
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_3, (DWORD32) g_pMyPlayerObj)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 3;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }

                                    }

                                }
                            }
                        }
                        if(data->GetData().Level >= m_CustomDataManager->m_RefFellowPetSystem[NameStr].Active_Level_4)
                        {
                            if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_4 > 0)
                            {
                                if(m_Player->m_FellowSkillData[0].Enable_Skill_4 == 1)
                                {
                                    int cooldown = g_pCGInterface->GetSkillCoolTimeManager()->FUN_009bba90(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_4);

                                    if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillType_4 == 0)
                                    {
                                        /// PET ICIN
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_4, (DWORD32)pUser)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 4;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }
                                    }
                                    else
                                    {
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_4, (DWORD32) g_pMyPlayerObj)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 4;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }

                                    }

                                }
                            }
                        }
                        if(data->GetData().Level >= m_CustomDataManager->m_RefFellowPetSystem[NameStr].Active_Level_5)
                        {
                            if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_5 > 0)
                            {
                                if(m_Player->m_FellowSkillData[0].Enable_Skill_5 == 1)
                                {
                                    int cooldown = g_pCGInterface->GetSkillCoolTimeManager()->FUN_009bba90(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_5);

                                    if(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillType_5 == 0)
                                    {
                                        /// PET ICIN
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_5, (DWORD32)pUser)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 5;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }
                                    }
                                    else
                                    {
                                        if (cooldown == 0 && !g_pMyPlayerObj->TargetIsBuffInUse(m_CustomDataManager->m_RefFellowPetSystem[NameStr].SkillID_5, (DWORD32) g_pMyPlayerObj)) {

                                            CMsgStreamBuffer buf(0x189B);
                                            buf << m_Player->m_FellowSkillData[0].ID64;
                                            buf << TO_NSTRING(NameStr);
                                            buf << (byte) 5;
                                            buf << pUser->GetUniqueId();
                                            SendMsg(buf);
                                            break;
                                        }

                                    }

                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
BOOL CGInterface::OnCharInSusspendMode(UINT nChar, UINT nRepCnt, UINT nFlags) {
    return reinterpret_cast<BOOL (__thiscall *)(CGInterface *, UINT, UINT, UINT)>(0x0078ae00)(this, nChar, nRepCnt, nFlags);
}

UINT CGInterface::FUN_00778310(UINT &nChar, UINT &nFlags) {
    return reinterpret_cast<UINT (__thiscall *)(CGInterface *, UINT &, UINT &)>(0x00778310)(this, nChar, nFlags);
}
undefined CGInterface::IsInteractionBlocked(undefined1 p1)
{
    return reinterpret_cast<undefined(__thiscall *)(CGInterface *, undefined1)>(0x0077c8e0)(this, p1);
}
void CGInterface::FUN_0079ac50(int p1, int p2)
{
    reinterpret_cast<void (__thiscall *)(CGInterface *, int, int)>(0x0079ac50)(this, p1, p2);
}
CIFItemMall* CGInterface::GetItemMall(){
    return m_IRM.GetResObj<CIFItemMall>(50, 1);
}
void CGInterface::sub_79A620()
{
    reinterpret_cast<void (__thiscall *)(CGInterface *)>(0x79A620)(this);
}
CSkillCoolTimeManager * CGInterface::GetSkillCoolTimeManager()
{
    return reinterpret_cast<CSkillCoolTimeManager*(__thiscall *)(CGInterface* )>(0x00778bb0)(this);
}
void CGInterface::OnItemMallSectionControl(bool bCreate) {
    CIFItemMall* pItemMall = m_IRM.GetResObj<CIFItemMall>(50, 1);

    if(bCreate) {
        if(!pItemMall) {
            m_IRM.CreateInterfaceSection("ItemMall", this);
            FUN_0079ac50(50, 0);
            pItemMall = m_IRM.GetResObj<CIFItemMall>(50, 1);
            if (pItemMall) {
                pItemMall->UpdateMenuSize();
                pItemMall->ShowGWnd(true);
                FUN_0079a7e0(pItemMall);
            }
        }
    } else {
        CGEffSoundBody::get()->PlaySound(L"snd_quest");
        if(pItemMall != NULL)
        {
            pItemMall->ShowGWnd(false);

            m_IRM.DeleteCreatedSection("ItemMall");
        }

    }
}
namespace
{
    bool g_windowMessageEscapeHandled = false;
}

void CGInterface::SetWindowMessageEscapeHandled(bool handled)
{
    g_windowMessageEscapeHandled = handled;
}

bool CGInterface::ConsumeWindowMessageEscapeHandled()
{
    const bool handled = g_windowMessageEscapeHandled;
    g_windowMessageEscapeHandled = false;
    return handled;
}

bool CGInterface::OnEscapePressed() {

    CIFTradeCaptchaWnd* tradeCaptchaWnd =
        GetGuiFromList<CIFTradeCaptchaWnd>(TRADE_SELL_CAPTCHA_WINDOW_ID);
    if (tradeCaptchaWnd && tradeCaptchaWnd->IsVisible())
    {
        tradeCaptchaWnd->CancelFromKeyboard();
        return true;
    }

    CIFOfflineStallConfirmWnd* offlineStallConfirmWnd =
        GetGuiFromList<CIFOfflineStallConfirmWnd>(OFFLINE_STALL_CONFIRM_WINDOW_ID);
    if (offlineStallConfirmWnd && offlineStallConfirmWnd->IsVisible())
    {
        offlineStallConfirmWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFLuckySpinWnd* luckySpinWnd = GetGuiFromList<CIFLuckySpinWnd>(LUCKY_SPIN_WINDOW_ID);
    if (luckySpinWnd && luckySpinWnd->IsVisible())
    {
        luckySpinWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFSpecialOffersWnd* specialOffersWnd = GetGuiFromList<CIFSpecialOffersWnd>(SPECIAL_OFFERS_WINDOW_ID);
    if (specialOffersWnd && specialOffersWnd->IsVisible())
    {
        specialOffersWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFKillerAnimationWnd* killerAnimationWnd = GetGuiFromList<CIFKillerAnimationWnd>(KILLER_ANIMATION_WINDOW_ID);
    if (killerAnimationWnd && killerAnimationWnd->IsVisible())
    {
        killerAnimationWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFDropLogsWnd* dropLogsWnd = GetGuiFromList<CIFDropLogsWnd>(DROP_LOGS_WINDOW_ID);
    if (dropLogsWnd && dropLogsWnd->IsVisible())
    {
        dropLogsWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFPvpChallengeAnswerWnd* pvpAnswerWnd = GetGuiFromList<CIFPvpChallengeAnswerWnd>(PVP_CHALLENGE_ANSWER_WINDOW_ID);
    if (pvpAnswerWnd && pvpAnswerWnd->IsVisible())
    {
        pvpAnswerWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFPvpChallengeWnd* pvpChallengeWnd = GetGuiFromList<CIFPvpChallengeWnd>(PVP_CHALLENGE_WINDOW_ID);
    if (pvpChallengeWnd && pvpChallengeWnd->IsVisible())
    {
        pvpChallengeWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
        return true;
    }

    CIFMenu *menu = g_pCGInterface->m_IRM.GetResObj<CIFMenu>(MainMenuID, 1);
    if(menu->CanSendPing)
    {
        menu->CanSendPing = false;
        g_Controler->SetCustomCursor(149);
        return true;
    }
    CIFDropLogWnd* pDropLogWnd = GetGuiFromList<CIFDropLogWnd>(DROP_LOG_WINDOW_ID);
    CIFItemMall* pItemMall = m_IRM.GetResObj<CIFItemMall>(50, 1);
    if(pItemMall != 0)
    {
        g_pCGInterface->OnItemMallSectionControl(false);
    }
    else if (pDropLogWnd && pDropLogWnd->IsVisible())
    {
        pDropLogWnd->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (this->m_IRM.GetResObj<CIFSettings>(1951, 1)->IsVisible())
    {
        this->m_IRM.GetResObj<CIFSettings>(1951, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (this->m_IRM.GetResObj<CIFItemTranslationWnd>(1361, 1)->IsVisible())
    {
        this->m_IRM.GetResObj<CIFItemTranslationWnd>(1361, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFAlchemyMacro>(AlchemyMacro, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFAlchemyMacro>(AlchemyMacro, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (g_pCGInterface->m_IRM.GetResObj<CIFVItemMallBuyItem>(NewItemMallBuyId, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFVItemMallBuyItem>(NewItemMallBuyId, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (g_pCGInterface->m_IRM.GetResObj<CIFVItemMall>(NewItemMallId, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFVItemMall>(NewItemMallId, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMallBuyItemList>(AvatarMallBuyListId, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMallBuyItemList>(AvatarMallBuyListId, 1)->ShowGWnd(false);
    }
    else if (g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMall>(AvatarMallId, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMall>(AvatarMallId, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFDailyLogin>(DailyLoginID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFDailyLogin>(DailyLoginID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFItemUnlocker>(Itemunlocker, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFItemUnlocker>(Itemunlocker, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFItemLocker>(ItemLocker, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFItemLocker>(ItemLocker, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFNewMsgBox>(1394, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFNewMsgBox>(1394, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFSocial>(SocialWndID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFSocial>(SocialWndID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (g_pCGInterface->m_IRM.GetResObj<CIFChangelog>(ChangelogID, 1)->IsVisible())
    {
        g_pCGInterface->m_IRM.GetResObj<CIFChangelog>(ChangelogID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFSavedLocation>(SavedLocation, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFSavedLocation>(SavedLocation, 1)->ShowGWnd(false);
        m_Player->ReverseSlot = 9999;
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFMovePartyMember>(MovePartyMember, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFMovePartyMember>(MovePartyMember, 1)->ShowGWnd(false);
        m_Player->ReverseSlot = 9999;
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if(m_IRM.GetResObj<CIFWeb>(CUSTOMWEBGUI, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFWeb>(CUSTOMWEBGUI, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->IsVisible()) {
        m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFMacro>(MacroID, 1)->IsVisible()) {
        m_IRM.GetResObj<CIFMacro>(MacroID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFChest>(ChestID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFChest>(ChestID, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFGrantName>(GrantNameID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFGrantName>(GrantNameID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFTitleManager>(TitleManagerID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFTitleManager>(TitleManagerID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFIconManager>(IconManagerID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFIconManager>(IconManagerID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFDynamicRanking>(DynamicRankingID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFDynamicRanking>(DynamicRankingID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFUniqueHistory>(UniqueHistoryID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFUniqueHistory>(UniqueHistoryID, 1)->OnCloseWnd();
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFEventRegister>(EventRegisterID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFEventRegister>(EventRegisterID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFEventSchedule>(EventScheduleID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFEventSchedule>(EventScheduleID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFAchievements>(AchievementsID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFAchievements>(AchievementsID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else if (m_IRM.GetResObj<CIFMenu>(MainMenuID, 1)->IsVisible())
    {
        m_IRM.GetResObj<CIFMenu>(MainMenuID, 1)->ShowGWnd(false);
        CGEffSoundBody::get()->PlaySound(L"snd_window_close");
    }
    else {
        return false;
    }

    return true;
}

BOOL CGInterface::OnCharIMPL(UINT nChar, UINT nRepCnt, UINT nFlags) {
    if (m_bDisappeard)
        return TRUE;
    
    if (m_SuspendMode != SUSPEND_MODE_NONE)
        return OnCharInSusspendMode(nChar, nRepCnt, nFlags);

    if (nChar == CHEAT_CONSOLE_TOGGLE_KEY)
        CGame::GetCheatConsole()->SetVisibleMode(VISIBLE_MODE_OPENING);

    CIFTradeCaptchaWnd* tradeCaptchaWnd =
        GetGuiFromList<CIFTradeCaptchaWnd>(TRADE_SELL_CAPTCHA_WINDOW_ID);
    if (tradeCaptchaWnd && tradeCaptchaWnd->IsVisible()) {
        if (tradeCaptchaWnd->HandleKeyboardInput(nChar)) {
            return TRUE;
        }
        if (nChar == VK_RETURN) {
            tradeCaptchaWnd->SubmitFromKeyboard();
            return TRUE;
        }
        if (nChar == VK_ESCAPE) {
            tradeCaptchaWnd->CancelFromKeyboard();
            return TRUE;
        }
    }

    if (nChar == VK_ESCAPE) {
        if (g_windowMessageEscapeHandled) {
            return TRUE;
        }

        if (OnEscapePressed()) {
            return TRUE;
        }

        return reinterpret_cast<BOOL (__thiscall *)(CGInterface *, UINT, UINT, UINT)>(0x0078b660)(
            this, nChar, nRepCnt, nFlags);
    }

    if (FUN_00778310(nChar, nFlags) != -1)
        return TRUE;
    
    if (nChar == VK_RETURN) {
        if (CGame::GetCheatConsole()->SetFocusOnInputBox() == FALSE)
            m_IRM.GetResObj<CIFChatViewer>(GDR_CHAT_BOARD, 1)->SetFocusToInputBox();

        return TRUE;
    }

    return TRUE;
}

void CGInterface::BeforeOnCreate() {

}
IDirect3DBaseTexture9 *CGInterface::CreateTexture(std::string path) {
    return (IDirect3DBaseTexture9 *) Fun_CacheTexture_Create(path.c_str());
}
void CGInterface::AfterOnCreate() {
    CreateFlorian0Event();

    RECT offlineStallConfirmRect = {0, 0, 420, 210};
    if (!GetGuiFromList<CIFOfflineStallConfirmWnd>(OFFLINE_STALL_CONFIRM_WINDOW_ID)) {
        CreateInstance(this, GFX_RUNTIME_CLASS(CIFOfflineStallConfirmWnd), offlineStallConfirmRect,
                       OFFLINE_STALL_CONFIRM_WINDOW_ID, 0);
    }

    // Keep this as a CGInterface overlay instead of attaching it to Joymax's
    // native CIFStall. Native resource windows can reject dynamically-added
    // children even though CreateInstance returns without a client crash.
    // The button anchors itself to GDR_STALL every frame.
    if (!GetGuiFromList<CIFOfflineStallButton>(OFFLINE_STALL_BUTTON_ID)) {
        RECT offlineStallButtonRect = {-500, -500, 96, 24};
        CreateInstance(this, GFX_RUNTIME_CLASS(CIFOfflineStallButton),
                       offlineStallButtonRect, OFFLINE_STALL_BUTTON_ID, 0);
    }

    RECT luckySpinWindowRect = {0, 0, 420, 480};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFLuckySpinWnd), luckySpinWindowRect, LUCKY_SPIN_WINDOW_ID, 0);

    RECT specialOffersWindowRect = {0, 0, 800, 500};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFSpecialOffersWnd), specialOffersWindowRect, SPECIAL_OFFERS_WINDOW_ID, 0);

    RECT killerAnimationWindowRect = {0, 0, 800, 500};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFKillerAnimationWnd), killerAnimationWindowRect, KILLER_ANIMATION_WINDOW_ID, 0);

    RECT dropLogsWindowRect = {0, 0, 760, 420};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFDropLogsWnd), dropLogsWindowRect, DROP_LOGS_WINDOW_ID, 0);

    RECT pvpChallengeWindowRect = {0, 0, 392, 214};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFPvpChallengeWnd), pvpChallengeWindowRect, PVP_CHALLENGE_WINDOW_ID, 0);

    RECT pvpChallengeAnswerRect = {0, 0, 350, 176};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFPvpChallengeAnswerWnd), pvpChallengeAnswerRect, PVP_CHALLENGE_ANSWER_WINDOW_ID, 0);

    EnsurePickInventoryWindow();

    RECT tradeCaptchaRect = {0, 0, 520, 326};
    CreateInstance(this, GFX_RUNTIME_CLASS(CIFTradeCaptchaWnd), tradeCaptchaRect, TRADE_SELL_CAPTCHA_WINDOW_ID, 0);
    CIFTradeCaptchaWnd* tradeCaptchaWnd = GetGuiFromList<CIFTradeCaptchaWnd>(TRADE_SELL_CAPTCHA_WINDOW_ID);
    if (tradeCaptchaWnd) {
        tradeCaptchaWnd->ShowGWnd(false);
    }

m_CustomDataManager->font = theApp.GetFont(0);
}

void CGInterface::ShowMessage_Quest(const std::n_wstring &msg) {
    CIFNotify *notify = m_IRM.GetResObj<CIFNotify>(GDR_UPDATE_QUEST_INFO, 1);
    notify->ShowMessage(msg);
}

void CGInterface::ShowMessage_Notice(const std::n_wstring &msg) {
    CIFNotify *notify = m_IRM.GetResObj<CIFNotify>(GDR_NOTICE, 1);
    notify->ShowMessage(msg);
}

void CGInterface::ShowMessage_Warning(const std::n_wstring &msg) {
    CIFNotify *notify = m_IRM.GetResObj<CIFNotify>(GDR_WARNING_WND, 1);
    notify->ShowMessage(msg);
}

int CGInterface::Get_SelectedObjectId() {
    return this->m_selectedObjectId;
}

CIFTimerWnd *CGInterface::Get_TimerWindow() {
    return this->m_timerWindow;
}

CIFQuickStateHalfWnd *CGInterface::Get_QuickStateHalfWnd() {
    return this->N00002637;
}

void CGInterface::WriteErrorMessage(byte errorType, unsigned __int16 errorCode, int colorARGB, int a5, int a6) {
    CIFSystemMessage *pSystemMsg = m_IRM.GetResObj<CIFSystemMessage>(GDR_SYSTEM_MESSAGE_VIEW, 1);
    if (pSystemMsg == NULL)
        return;

    pSystemMsg->WriteErrorMessage(errorType, errorCode, colorARGB, a5, a6);
}

void CGInterface::WriteSystemMessage(eLogType btLevel, LPCWSTR lpszText) {
    CIFSystemMessage *pSystemMsg = m_IRM.GetResObj<CIFSystemMessage>(GDR_SYSTEM_MESSAGE_VIEW, 1);
    if (!pSystemMsg->IsLogAble(btLevel))
        return;

    D3DCOLOR dwColor;
    if ((btLevel == SYSLOG_COMBAT) || (btLevel == SYSLOG_GUIDE)) {
        dwColor = D3DCOLOR_ARGB(255, 186, 207, 242);
    } else {
        dwColor = D3DCOLOR_ARGB(255, 220, 201, 155);
    }

    pSystemMsg->WriteMessage(255, dwColor, lpszText, 0, 1);
}


void CGInterface::CallNIFEnchantWnd(char param_1)
{
    /*if(param_1 == 0)
    {
        if(this->GetGuiFromList<CNIFEnchantWnd>(168) != NULL)
        {
            CNIFEnchantWnd * autpot = this->GetGuiFromList<CNIFEnchantWnd>(168);
            if(autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro) != NULL)
            {
                CIFAlchemyMacro * p = GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro);
                autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro)->EraseWindowObj2(p);
            }
        }
    }*/
    reinterpret_cast<void(__thiscall *)(CGInterface *, char)>(0x00798e20)(this, param_1);
  /*  if(param_1 == 1)
    {
        wnd_rect sz;
        sz.pos.x = 0;
        sz.pos.y = 0;
        sz.size.width = 500;
        sz.size.height = 374;
        if(this->GetGuiFromList<CNIFEnchantWnd>(168) != NULL)
        {
            CNIFEnchantWnd * autpot = this->GetGuiFromList<CNIFEnchantWnd>(168);
         if(autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro) == NULL)
         {
          CGWnd::CreateInstance(autpot, GFX_RUNTIME_CLASS(CIFAlchemyMacro), sz, AlchemyMacro, 0);
          autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro)->MoveGWnd(autpot->GetPos().x + 300, autpot->GetPos().y + 169);
          autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro)->BringToFront();
             autpot->GetGuiFromList<CIFAlchemyMacro>(AlchemyMacro)->ShowGWnd(true);
        }
        }
    }
*/
}
void CGInterface::sub_787C10(SChatMetaData &meta) {
    reinterpret_cast<void (__thiscall *)(CGInterface *, SChatMetaData *)>(0x787C10)(this, &meta);
}

int CGInterface::TryParseChatCommands(const wchar_t *text, RECT &r, std::vector<void *> &v) {
    /// \todo implement me.
    return reinterpret_cast<
            int (__thiscall *)(CGInterface *, const wchar_t *, RECT *, std::vector<void *> *)
            >(0x0078BEA0)(this, text, &r, &v);
}

void CGInterface::FUN_00777c30(ChatType type, const wchar_t *message, D3DCOLOR color, int a5) {
    CIFChatViewer *chatViewer = m_IRM.GetResObj<CIFChatViewer>(GDR_CHATVIEWER, 1);
    chatViewer->FUN_007aca30(type, color, message, 0, a5);
}

void CGInterface::WriteInMarketChatMsg(BYTE btChatType, LPCWSTR lpszMsg, D3DCOLOR dwColor) {
    m_pMarketChatModule[btChatType]->WriteChatMessage(lpszMsg, dwColor);
}

void CGInterface::AddTargetToWhisperList(std::n_wstring &recipient) {
    m_IRM.GetResObj<CIFChatViewer>(GDR_CHAT_BOARD, true)->AddWhisperTargetToList(recipient);
}

void CGInterface::ShowLogMessage(int color, const wchar_t* msg) {
    GetSystemMessageView()->WriteMessage(0xff, color, msg, 0, 1);
}

void CGInterface::Set_SelectedObjectId(int i)
{
    reinterpret_cast<void (__thiscall *)(CGInterface *, int)>(0x00780ee0)(this, i);
}


CNIFWorldMap *CGInterface::GetCNIFWorldMap() {
    return reinterpret_cast<CNIFWorldMap * (__thiscall *)(CGInterface *)>(0x00799920)(this);
}
int CGInterface::OnKeyDown(int keycode, int a3, int a4) {

    // Returning false is the native contract for consuming a shortcut. Only
    // suppress Escape when the window-message path actually closed a custom
    // window; otherwise delegate to Joymax so its original menu still works.
    if (keycode == VK_ESCAPE && g_windowMessageEscapeHandled) {
        return false;
    }

    // Depending on the client input route this callback receives either the
    // Win32 virtual key or the DirectInput scan code.
    if (keycode == 'O' || keycode == 0x18) {
        if (TogglePickInventoryWindow())
            return false;
    }

    if(m_Settings->EnableMacro)
    {
        if (keycode == 0x54) {

            if (this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->IsVisible()) {
                this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->ShowGWnd(false);
                CGEffSoundBody::get()->PlaySound(L"snd_window_close");
            }
            else {
                this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->ActivateTabPage(0);

                this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->ActivateTabPage(0);

                //   this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoPotionSlot->LoadInfo();
               // this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->AutoSkillSlot->LoadInfo();
                this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->UpdateMenuSize();
                //this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->ActivateTabPage(0);
                this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->ShowGWnd(true);



                CGEffSoundBody::get()->PlaySound(L"snd_window_open");
                wnd_pos r;
                r = this->m_IRM.GetResObj<CIFMacroMenu>(MacroMenuID, 1)->GetPos();
                this->m_IRM.GetResObj<CIFMacro>(MacroID, 1)->UpdateMenuSize();
                this->m_IRM.GetResObj<CIFMacro>(MacroID, 1)->ShowGWnd(true);
                this->m_IRM.GetResObj<CIFMacro>(MacroID, 1)->MoveGWnd(r.x - 313, r.y);

            }
            return true;
        }

    }
    if(m_Settings->SecondarySlot)
    {
        short ALTState = GetKeyState(VK_SPACE);
        if((ALTState == -127 || ALTState == -128) && keycode == 0x30) /// TODO sp + 0
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(0);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x31) /// TODO sp + 1
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(1);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x32) /// TODO sp + 2
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(2);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x33) /// TODO sp + 3
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(3);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x34) /// TODO sp + 4
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(4);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x35) /// TODO sp + 5
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(5);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x36) /// TODO sp + 6
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(6);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x37) /// TODO sp + 7
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(7);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x38) /// TODO sp + 8
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(8);

            return false;
        }
        if((ALTState == -127 || ALTState == -128) && keycode == 0x39) /// TODO sp + 9
        {
            this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UseViaSpace(9);

            return false;
        }
        if(keycode == 116) /// TODO f5
        {
            if(this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo != 1)
            {
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo = 1;
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->hotkey->SetText(L"F5");
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UpdateSlots();
            }
        }
        else if(keycode == 117) /// TODO f6
        {
            if(this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo != 2)
            {
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo = 2;
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->hotkey->SetText(L"F6");
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UpdateSlots();
            }
        }
        else if(keycode == 118) /// TODO f7
        {
            if(this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo != 3)
            {
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo = 3;
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->hotkey->SetText(L"F7");
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UpdateSlots();
            }
        }
        else if(keycode == 119) /// TODO f8
        {
            if(this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo != 4)
            {
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->ActivePageNo = 4;
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->hotkey->SetText(L"F8");
                this->m_IRM.GetResObj<CIFExtQuickSlotCustom>(CustomQuickSlot, 1)->UpdateSlots();
            }
        }
    }
    if(m_Settings->EnableNewItemMall)
    {
        if (keycode == 0x79) {

            if (!g_pCGInterface->m_IRM.GetResObj<CIFVItemMall>(NewItemMallId, 1)->IsVisible() && !g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMall>(AvatarMallId, 1)->IsVisible()) {
                if (!g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->IsVisible()) {
                    CGEffSoundBody::get()->PlaySound(L"snd_window_open");

                    g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->UpdateMenuSize();
                    g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->ShowGWnd(true);

                    g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->BringToFront();

                }

            }
            return true;
        }

    }
    return reinterpret_cast<int(__thiscall *)(CGInterface *, int, int, int)>(0x00780610)(this, keycode, a3, a4);
}
void CGInterface::UnLockMovement()
{

    reinterpret_cast<void(__thiscall *)(CGInterface *)>(0x0079e350)(this);
}
void CGInterface::LockMovement(int param)
{
    reinterpret_cast<void(__thiscall *)(CGInterface *, int)>(0x00787bd0)(this, param);
}
int CGInterface::Wtf() {
    if (!g_pCGInterface->m_IRM.GetResObj<CIFVItemMall>(NewItemMallId, 1)->IsVisible() && !g_pCGInterface->m_IRM.GetResObj<CIFVAvatarMall>(AvatarMallId, 1)->IsVisible()) {
        if (!g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->IsVisible()) {
            CGEffSoundBody::get()->PlaySound(L"snd_window_open");

            g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->UpdateMenuSize();
            g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->ShowGWnd(true);

            g_pCGInterface->m_IRM.GetResObj<CIFVSelectMall>(SelectMallId, 1)->BringToFront();
        }

    }
    return 1;
}
void CGInterface::CreateCustomMessageBox(int ID, int MasteryID)
{
    if(!this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->IsVisible())
    {
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->selectedCheckBoxID = ID;
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->selectedMasteryID = MasteryID;
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->UpdateMenuSize();
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->ShowGWnd(true);
    }
    else
    {
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->selectedCheckBoxID = ID;
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->selectedMasteryID = MasteryID;
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->UpdateMenuSize();
        this->m_IRM.GetResObj<CIFCustomMessageBox>(CustomMessageBox, 1)->ShowGWnd(true);
    }
}

CItemReuseDelayManager * CGInterface::GetItemReuseDelayManager()
{
    if(m_pCItemReuseDelayManager != NULL)
    {
        return m_pCItemReuseDelayManager;
    }
    return NULL;
}
