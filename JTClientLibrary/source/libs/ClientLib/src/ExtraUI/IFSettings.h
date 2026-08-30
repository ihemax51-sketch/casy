#pragma once
#include <SRIFLib/IFSlotWithHelpEx.h>
#include <ICUser.h>
#include <IFCheckBox.h>
#include "IFWnd.h"
#include "unsorted.h"
class CIFSettings : public CIFMainFrame {
GFX_DECLARE_DYNCREATE(CIFSettings)
GFX_DECLARE_MESSAGE_MAP(CIFSettings)
public:
    CIFSettings(void);
    ~CIFSettings(void);
    bool OnCreate(long ln) override;
    void UpdateMenuSize();
    void ClickSaveButton();
    void OnUpdate() override;
    CIFCheckBox* EnableShowFps;
    CIFCheckBox* EnableZoom;
    CIFCheckBox* EnableActiveWnd;
    CIFCheckBox* HideCharInfo;
    CIFCheckBox* RememberPC;
    CIFCheckBox* EnableHUD;
    bool HideCharInfoSetting;
    bool RememberPCSetting;
    bool HudCommittedState;
void ClickFPSCheckBox();
void ClickHUDCheckBox();
void OpenButton(int timeoutSeconds);
void OnTimer(int timerId) override;
void ClickCancel();
    undefined1 OnCloseWnd() override;
    int OnMouseMove(int a1, int x, int y) override;
};
