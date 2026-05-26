//---------------------------------------------------------------------------

#ifndef FManageCustOrderH
#define FManageCustOrderH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvExComCtrls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <ExtCtrls.hpp>
#include <ImgList.hpp>
#include <ToolWin.hpp>
#include "cxClasses.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxEdit.hpp"
#include "cxFilter.hpp"
#include "cxGraphics.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxStyles.hpp"
#include <DB.hpp>

#include "JvExComCtrls.hpp"

#include "CNewSpecialForm.h"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxNavigator.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmManageCustOrder : public NewSpecialForm
{
__published:	// IDE-managed Components
	TStatusBar *StatusBar1;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TToolButton *ToolButton1;
	TToolButton *ToolButton4;
	TToolButton *ToolButton5;
	TJvPanel *JvPanel2;
	TcxGrid *GridProjects;
	TcxGridDBTableView *ViewProjects;
	TcxGridLevel *GridProjectsLevel1;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TIBDataSet *DatasetCustOrder;
	TDataSource *DSCustOrder;
	TIBStringField *DatasetCustOrderAFM;
	TIBStringField *DatasetCustOrderNAME;
	TIBStringField *DatasetCustOrderADDR;
	TcxGridDBColumn *ViewProjectsAFM;
	TcxGridDBColumn *ViewProjectsNAME;
	TcxGridDBColumn *ViewProjectsADDR;
	TIntegerField *DatasetCustOrderCUST_ID;
	TIntegerField *DatasetCustOrderORDER;
	TcxGridDBColumn *ViewProjectsORDER;
	void __fastcall ToolButton4Click(TObject *Sender);
	void __fastcall ToolButton1Click(TObject *Sender);
	void __fastcall ViewProjectsCellClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled);
	void __fastcall DatasetCustOrderAfterScroll(TDataSet *DataSet);
private:	// User declarations
	void inspectOrder();
public:		// User declarations
	__fastcall TFrmManageCustOrder(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageCustOrder *FrmManageCustOrder;
//---------------------------------------------------------------------------
#endif
