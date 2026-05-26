//---------------------------------------------------------------------------

#ifndef FManageDistributionAimH
#define FManageDistributionAimH
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
class TFrmManageDistAim : public NewSpecialForm
{
__published:	// IDE-managed Components
	TImageList *ImageList1;
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
	TJvDBUltimGrid *GridDistributionMethods;
	TStatusBar *StatusBar1;
	TIBDataSet *DatasetDistAim;
	TDataSource *DSDistAim;
	TIntegerField *DatasetDistAimDISTAIM_ID;
	TIBStringField *DatasetDistAimDESCRIPTION;
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall DatasetDistAimAfterCancel(TDataSet *DataSet);
	void __fastcall DatasetDistAimAfterPost(TDataSet *DataSet);
	void __fastcall DatasetDistAimBeforeEdit(TDataSet *DataSet);
	void __fastcall DatasetDistAimBeforeInsert(TDataSet *DataSet);
	void __fastcall GridDistributionMethodsUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManageDistAim(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageDistAim *FrmManageDistAim;
//---------------------------------------------------------------------------
#endif
