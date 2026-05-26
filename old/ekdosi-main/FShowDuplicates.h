//---------------------------------------------------------------------------

#ifndef FShowDuplicatesH
#define FShowDuplicatesH
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
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExDBGrids.hpp"
#include <DBGrids.hpp>
#include <Grids.hpp>
#include <DB.hpp>
#include <DBClient.hpp>
#include <FMTBcd.hpp>
#include <SimpleDS.hpp>
#include <SqlExpr.hpp>
#include <WideStrings.hpp>
#include "JvSplit.hpp"
#include "JvSplitter.hpp"

#include "CNewSpecialForm.h"
#include <System.ImageList.hpp>
#include <Data.DBXMySQL.hpp>
//---------------------------------------------------------------------------
class TFrmShowDuplicates : public NewSpecialForm
{
__published:	// IDE-managed Components
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridDuplicates;
	TStatusBar *StatusBar1;
	TSQLConnection *sqlConnection;
	TDataSource *DSDuplicates;
	TSimpleDataSet *DatasetDups;
	TJvPanel *PanelDetail;
	TJvDBUltimGrid *GridDetail;
	TJvSplitter *JvSplitter1;
	TSimpleDataSet *DatasetProduct;
	TDataSource *DSProducts;
	TStringField *DatasetDupsproduct_code;
	TFMTBCDField *DatasetDupscount;
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall DatasetProductBeforeOpen(TDataSet *DataSet);
	void __fastcall DatasetDupsAfterScroll(TDataSet *DataSet);
	void __fastcall ToolPreviousClick(TObject *Sender);
	void __fastcall ToolNextClick(TObject *Sender);
	void __fastcall ToolRefreshClick(TObject *Sender);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmShowDuplicates(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowDuplicates *FrmShowDuplicates;
//---------------------------------------------------------------------------
#endif
