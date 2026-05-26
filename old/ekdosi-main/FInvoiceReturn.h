//---------------------------------------------------------------------------

#ifndef FInvoiceReturnH
#define FInvoiceReturnH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvDotNetControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExMask.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolEdit.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <Mask.hpp>
#include "JvSpin.hpp"

#include "CNewSpecialForm.h"
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
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxStyles.hpp"
#include "cxNavigator.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
//---------------------------------------------------------------------------
class TFrmInvoiceReturn : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetProductQty;
	TIBQuery *QryInvoice;
	TIntegerField *QryInvoiceINVOICE_ID;
	TIBStringField *QryInvoiceINVCODE;
	TIntegerField *QryInvoiceCUST_ID;
	TIBStringField *QryInvoiceINVTYPE;
	TDateField *QryInvoiceINVDATE;
	TSmallintField *QryInvoicePRINTED;
	TDateField *QryInvoiceDELIVERYDATE;
	TIntegerField *QryInvoiceDISTRAIM_ID;
	TIntegerField *QryInvoiceDELMETHOD_ID;
	TIntegerField *QryInvoicePAYMETH_ID;
	TIBBCDField *QryInvoiceDISCOUNT;
	TIBBCDField *QryInvoicePRICE;
	TIBBCDField *QryInvoicePRICEWVAT;
	TMemoField *QryInvoiceNOTES;
	TStatusBar *StatusBar1;
	TDataSource *DSProductQty;
	TJvPanel *JvPanel1;
	TJvPanel *JvPanel2;
	TJvPanel *JvPanel3;
	TJvDotNetButton *cmdAccept;
	TJvDotNetButton *JvDotNetButton2;
	TJvDotNetButton *cmdPrint;
	TJvDateEdit *editDate;
	TJvTimeEdit *editTime;
	TLabel *Label1;
	TLabel *Label2;
	TIntegerField *DatasetProductQtyINVLINE_ID;
	TIntegerField *DatasetProductQtyINVOICE_ID;
	TIntegerField *DatasetProductQtyPRODUCT_ID;
	TIBBCDField *DatasetProductQtyPRICE_PER_ITEM;
	TIBBCDField *DatasetProductQtyPRICE;
	TIBBCDField *DatasetProductQtyPRICEWVAT;
	TIntegerField *DatasetProductQtyPRODUCT_ID1;
	TIBStringField *DatasetProductQtyBARCODE;
	TIBStringField *DatasetProductQtyDESCRIPTION_SHORT;
	TIntegerField *DatasetProductQtyCAT_ID;
	TIntegerField *DatasetProductQtyVATCAT_ID;
	TIBBCDField *DatasetProductQtyBUY_PRICE;
	TIBBCDField *DatasetProductQtySELL_PRICE;
	TIBBCDField *DatasetProductQtyPRICE_WVAT;
	TDateField *DatasetProductQtyDATE_INSERTED;
	TMemoField *DatasetProductQtyDESCRIPTION;
	TIBBCDField *DatasetProductQtyQTY;
	TIBBCDField *DatasetProductQtyQTY_GIVEN;
	TIBBCDField *DatasetProductQtyQTY_RETURNED;
	TIBBCDField *DatasetProductQtyDISCOUNT;
	TIBBCDField *DatasetProductQtyVATPERCENT;
	TIBBCDField *DatasetProductQtyRESERVE;
	TIBBCDField *DatasetProductQtyRESERVE_SECURE;
	TJvDotNetButton *JvDotNetButton1;
	TIBStringField *DatasetProductQtyPRODUCT_DESCR;
	TIBStringField *DatasetProductQtyMETRIC_UNIT;
	TIBStringField *DatasetProductQtyNOTES;
	TIntegerField *DatasetProductQtyMETRIC_ID;
	TDateTimeField *DatasetProductQtyLAST_UPDATE;
	TIntegerField *DatasetProductQtyINVLINE_ID1;
	TIBBCDField *DatasetProductQtyQTY_SENT;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridItems;
	TcxGridDBTableView *ViewItems;
	TcxGridLevel *GridItemsLevel1;
	TcxGridDBColumn *ViewItemsBARCODE;
	TcxGridDBColumn *ViewItemsDESCRIPTION_SHORT;
	TcxGridDBColumn *ViewItemsQTY;
	TcxGridDBColumn *ViewItemsQTY_GIVEN;
	TcxGridDBColumn *ViewItemsQTY_RETURNED;
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall cmdAcceptClick(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
private:	// User declarations
	int findCumInvoiceDate(TDate _date);
	void createReturnInvoice();
	TDate runningDate;
	int selRow, selCol;
	int cumInvoiceId;
	AnsiString getReportFilename ();
public:		// User declarations
	__fastcall TFrmInvoiceReturn(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmInvoiceReturn *FrmInvoiceReturn;
//---------------------------------------------------------------------------
#endif
