#pragma once
#include <CustomInterface/IFPopupList.h>
#include <IFVerticalScroll.h>
#include "IFMainFrame.h"
#include "IFEdit.h"

class CIFDynamicRanking : public CIFMainFrame
{
GFX_DECLARE_DYNCREATE(CIFDynamicRanking)
GFX_DECLARE_MESSAGE_MAP(CIFDynamicRanking)
private:
    CIFDynamicRanking(void);
    ~CIFDynamicRanking(void);
    bool OnCreate(long ln) override;
    void OnUpdate() override;
    void OnTimer(int) override;
    void On_BtnClick();
    void SendPacket(byte type);
public:
    void UpdateMenuSize();
    void Clear();
    void ResetData();
    void UpdateRanks();
    void UpdateSelfRank(std::n_wstring Name, int No, int Point);
    void Hide();
    CIFPopupList* m_popup;
    std::map<std::n_wstring, int> RankCategorys;
    struct RankStruct
    {
        int LineNum;
        std::wstring Charname;
        std::wstring Guild;
        std::wstring Points;
    };
    std::vector<RankStruct> RankList;


    int m_CurrentIndex;
    int m_MaxIndex;
void OpenButton(int timeoutMilliseconds);
void On_NextBtn();
void On_PrevBtn();
void UpdateText(int Number);
void LoadPage(int pageNumber);
};
