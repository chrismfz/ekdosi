#ifndef FMetricUnitsH
#define FMetricUnitsH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLabel.hpp"
#include "JvPanel.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>
#include <ToolWin.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmMetricUnits : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetMetricUnit;
	TIntegerField *DatasetMetricUnitMETRIC_ID;
	TIBStringField *DatasetMetricUnitNAME;
	TMemoField *DatasetMetricUnitNOTES;
	TImageList *ImageList1;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridMetricUnits;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolAdd;
	TToolButton *ToolButton4;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvRollOut *RollDetail;
	TLabel *Label1;
	TLabel *Label9;
	TJvLabel *lblCheck;
	TJvDotNetDBEdit *DBEditName;
	TJvDotNetDBMemo *MemoDetails;
	TStatusBar *StatusBar1;
	TDataSource *DSMetricUnits;
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall ToolAddClick(TObject *Sender);
private:	// User declarations
	bool checkFields();
public:		// User declarations
	__fastcall TFrmMetricUnits(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmMetricUnits *FrmMetricUnits;
//---------------------------------------------------------------------------
#endif
