//---------------------------------------------------------------------------

#ifndef FManageProductCategoriesH
#define FManageProductCategoriesH
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
#include "JvDBDotNetControls.hpp"
#include "JvRollOut.hpp"
#include <DBCtrls.hpp>
#include <Mask.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExDBGrids.hpp"
#include <DBGrids.hpp>
#include <Grids.hpp>
#include <DB.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmManPrCateg : public NewSpecialForm
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
	TStatusBar *StatusBar1;
	TJvRollOut *RollDetail;
	TLabel *Label1;
	TLabel *Label9;
	TJvDotNetDBEdit *editDescription;
	TJvDotNetDBMemo *MemoDetails;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridCategories;
	TIBDataSet *DatasetPrCategories;
	TDataSource *DSPrCategories;
	TIntegerField *DatasetPrCategoriesCAT_ID;
	TIBStringField *DatasetPrCategoriesDESCRIPTION_SHORT;
	TMemoField *DatasetPrCategoriesDESCRIPTION;
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall GridCategoriesUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManPrCateg(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManPrCateg *FrmManPrCateg;
//---------------------------------------------------------------------------
#endif
