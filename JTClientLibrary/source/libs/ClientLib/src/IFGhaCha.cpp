//
// Created by YUMBUL on 24.08.2024.
//

#include "IFGhaCha.h"
#include "GInterface.h"
#include "IFInventory.h"
#include <CustomData/CustomCICPlayer.h>

namespace {

const int kWaitForResultTimer = 1313;
const int kStartNextPlayTimer = 1314;
const int kNoAutomationSetting = 700;
const int kAutoRefillSetting = 701;
const int kAutoPlaySetting = 702;

void StopMagicPopTimer(CIFGhaCha *window, int timerId)
{
    window->KillTimer(timerId);
    if (m_Player != NULL)
        m_Player->m_MagicPopTimerRunning = false;
}

CSOItem *FindMagicPopCard(int &inventorySlot)
{
    inventorySlot = -1;

    if (g_pCGInterface == NULL || g_pCGInterface->GetMainPopup() == NULL)
        return NULL;

    CIFInventory *inventory = g_pCGInterface->GetMainPopup()->GetInventory();
    if (inventory == NULL)
        return NULL;

    const int slotCount = inventory->InventorySlotCount();
    for (int slot = 0; slot < slotCount; ++slot) {
        CSOItem *item = inventory->GetItemBySlot(slot);
        if (item != NULL && item->m_blValid != 0 &&
            item->GetItemData() != NULL && item->GetItemData()->IsMagicPop()) {
            inventorySlot = slot;
            return item;
        }
    }

    return NULL;
}

} // namespace

void CIFGhaCha::PlayButton()
{
    if (m_Player != NULL && !m_Player->m_MagicPopTimerRunning &&
        m_Player->m_MagicPopSettings != kNoAutomationSetting) {
        m_Player->m_MagicPopTimerRunning = true;
        StartTimer(kWaitForResultTimer, 500);
    }

    reinterpret_cast<void(__thiscall *)(CIFGhaCha *)>(0x007459b0)(this);
}

void CIFGhaCha::OnTimerIMPL(int timerId)
{
    reinterpret_cast<void(__thiscall *)(CIFGhaCha *, int)>(0x00746c70)(this, timerId);

    if (timerId == kStartNextPlayTimer) {
        KillTimer(kStartNextPlayTimer);

        if (m_Player != NULL && m_Player->m_MagicPopSettings == kAutoPlaySetting)
            PlayButton();

        return;
    }

    if (timerId != kWaitForResultTimer || m_Player == NULL)
        return;

    if (m_Player->m_MagicPopSettings != kAutoRefillSetting &&
        m_Player->m_MagicPopSettings != kAutoPlaySetting) {
        StopMagicPopTimer(this, kWaitForResultTimer);
        return;
    }

    if (MagicPopSlot == NULL || MagicPopSlot->ItemInfo == NULL ||
        MagicPopSlot->ItemInfo->GetItemData() == NULL) {
        StopMagicPopTimer(this, kWaitForResultTimer);
        return;
    }

    const SItemData *selectedItemData = MagicPopSlot->ItemInfo->GetItemData();

    // While the round is running the slot still contains a Magic Pop card.
    // The result replaces it with either a losing item or the winning card.
    if (selectedItemData->IsMagicPop())
        return;

    if (m_Player->m_MagicPopSettings == kAutoPlaySetting &&
        (selectedItemData->IsMagicPopWinCard() || selectedItemData->RefObjectId == 9239)) {
        StopMagicPopTimer(this, kWaitForResultTimer);
        return;
    }

    int inventorySlot = -1;
    CSOItem *nextCard = FindMagicPopCard(inventorySlot);
    if (nextCard == NULL) {
        StopMagicPopTimer(this, kWaitForResultTimer);
        return;
    }

    MagicPopSlot->SetSlotData(nextCard);
    MagicPopSlot->SetInventorySlotType(inventorySlot);

    if (PlayButtonMaybe != NULL)
        PlayButtonMaybe->SetEnabledState(true);

    StopMagicPopTimer(this, kWaitForResultTimer);

    if (m_Player->m_MagicPopSettings == kAutoPlaySetting)
        StartTimer(kStartNextPlayTimer, 1000);
}
