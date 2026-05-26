//---------------------------------------------------------------------------

#ifndef FManageVatCategoriesH
#define FManageVatCategoriesH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
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
#include "JvDotNetControls.hpp"
#include "JvCheckBox.hpp"
#include "JvExStdCtrls.hpp"

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmManageVatCategories : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetVatCategories;
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
	TJvDBUltimGrid *GridCategories;
	TJvRollOut *RollDetail;
	TLabel *Label1;
	TLabel *Label9;
	TJvDotNetDBEdit *editDescription;
	TJvDotNetDBMemo *MemoDetails;
	TStatusBar *StatusBar1;
	TJvDotNetDBEdit *editValue;
	TLabel *Label2;
	TDataSource *DSVatCategories;
	TIntegerField *DatasetVatCategoriesVATCAT_ID;
	TIBStringField *DatasetVatCategoriesDESCRIPTION;
	TMemoField *DatasetVatCategoriesLONG_DESCRIPTION;
	TSmallintField *DatasetVatCategoriesDEFAULT_CAT;
	TToolButton *ToolAssignDefault;
	TToolButton *ToolButton3;
	TJvCheckBox *checkDefault;
	TIBBCDField *DatasetVatCategoriesVALUE;
	void __fastcall GridCategoriesDrawColumnCell(TObject *Sender,
          const TRect &Rect, int DataCol, TColumn *Column,
          TGridDrawState State);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall DatasetVatCategoriesAfterScroll(TDataSet *DataSet);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolAssignDefaultClick(TObject *Sender);
	void __fastcall editValueExit(TObject *Sender);
	void __fastcall GridCategoriesUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManageVatCategories(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageVatCategories *FrmManageVatCategories;
//---------------------------------------------------------------------------
#endif
