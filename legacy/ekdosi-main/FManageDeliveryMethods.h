//---------------------------------------------------------------------------

#ifndef FManageDeliveryMethodsH
#define FManageDeliveryMethodsH
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
#include "JvExtComponent.hpp"
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
class TFrmManageDeliveryMeth : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridDeliveryM;
	TDataSource *DSDeliveryMeth;
	TImageList *ImageList1;
	TIBDataSet *DatasetDeliveryM;
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
	TStatusBar *StatusBar1;
	TIntegerField *DatasetDeliveryMMETHOD_ID;
	TIBStringField *DatasetDeliveryMDESCRIPTION;
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall DatasetDeliveryMBeforeEdit(TDataSet *DataSet);
	void __fastcall DatasetDeliveryMAfterPost(TDataSet *DataSet);
	void __fastcall DatasetDeliveryMBeforeInsert(TDataSet *DataSet);
	void __fastcall DatasetDeliveryMAfterCancel(TDataSet *DataSet);
	void __fastcall GridDeliveryMUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManageDeliveryMeth(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageDeliveryMeth *FrmManageDeliveryMeth;
//---------------------------------------------------------------------------
#endif
