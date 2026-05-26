//---------------------------------------------------------------------------

#ifndef FManagePaymentMethodsH
#define FManagePaymentMethodsH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvHtControls.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <ToolWin.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmManagePaymentMeth : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetPaymentM;
	TImageList *ImageList1;
	TDataSource *DSDeliveryMeth;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridPaymentM;
	TStatusBar *StatusBar1;
	TIntegerField *DatasetPaymentMMETHOD_ID;
	TIBStringField *DatasetPaymentMDESCRIPTION;
	TLabel *Label1;
	TIntegerField *DatasetPaymentMDUE_DAYS;
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall GridPaymentMDrawColumnCell(TObject *Sender, const TRect &Rect,
          int DataCol, TColumn *Column, TGridDrawState State);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall DatasetPaymentMAfterPost(TDataSet *DataSet);
	void __fastcall DatasetPaymentMAfterCancel(TDataSet *DataSet);
	void __fastcall GridPaymentMUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManagePaymentMeth(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManagePaymentMeth *FrmManagePaymentMeth;
//---------------------------------------------------------------------------
#endif
