//---------------------------------------------------------------------------

#ifndef FEditInvoiceH
#define FEditInvoiceH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBControls.hpp"
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBLookup.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExMask.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolEdit.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <Mask.hpp>
#include "JvValidateEdit.hpp"
#include "JvSpin.hpp"

#include "CNewSpecialForm.h"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxDBEdit.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxTextEdit.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxCalendar.hpp"
#include "cxDBLookupComboBox.hpp"
#include "cxDBLookupEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxLookupEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxMemo.hpp"
//---------------------------------------------------------------------------
class TFrmEditInvoice : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBQuery *QrySelCustomer;
	TIntegerField *QrySelCustomerCUST_ID;
	TIBStringField *QrySelCustomerAFM;
	TIBStringField *QrySelCustomerNAME;
	TIBStringField *QrySelCustomerADDRESS1;
	TIBStringField *QrySelCustomerADDRESS2;
	TIBStringField *QrySelCustomerCITY;
	TIBStringField *QrySelCustomerPOSTCODE;
	TIBStringField *QrySelCustomerPHONE1;
	TIBStringField *QrySelCustomerPHONE2;
	TIBStringField *QrySelCustomerFAX;
	TIBStringField *QrySelCustomerOCCUPATION;
	TIBStringField *QrySelCustomerTAXOFFICE;
	TMemoField *QrySelCustomerDETAILS;
	TIBQuery *QryDistAim;
	TIntegerField *QryDistAimDISTAIM_ID;
	TIBStringField *QryDistAimDESCRIPTION;
	TIBQuery *QryDeliveryMethod;
	TIntegerField *QryDeliveryMethodMETHOD_ID;
	TIBStringField *QryDeliveryMethodDESCRIPTION;
	TIBQuery *QryPaymentMeth;
	TIntegerField *QryPaymentMethMETHOD_ID;
	TIBStringField *QryPaymentMethDESCRIPTION;
	TIntegerField *QryPaymentMethDUE_DAYS;
	TIBQuery *QryInvTypes;
	TIBStringField *QryInvTypesINVTYPE_ID;
	TIBStringField *QryInvTypesNAME;
	TIBDataSet *DatasetInvoice;
	TIBStringField *DatasetInvoiceINVCODE;
	TIntegerField *DatasetInvoiceCUST_ID;
	TIBStringField *DatasetInvoiceINVTYPE;
	TDateField *DatasetInvoiceINVDATE;
	TIntegerField *DatasetInvoiceINVOICE_ID;
	TIntegerField *DatasetInvoicePAID;
	TDateField *DatasetInvoiceDELIVERYDATE;
	TIntegerField *DatasetInvoiceDISTRAIM_ID;
	TIntegerField *DatasetInvoiceDELMETHOD_ID;
	TIntegerField *DatasetInvoicePAYMETH_ID;
	TIBBCDField *DatasetInvoiceDISCOUNT;
	TIBBCDField *DatasetInvoicePRICE;
	TIBBCDField *DatasetInvoicePRICEWVAT;
	TIBDataSet *DatasetInvLines;
	TIntegerField *DatasetInvLinesINVLINE_ID;
	TIntegerField *DatasetInvLinesINVOICE_ID;
	TIntegerField *DatasetInvLinesPRODUCT_ID;
	TIBBCDField *DatasetInvLinesPRICE_PER_ITEM;
	TIBBCDField *DatasetInvLinesPRICE;
	TIBBCDField *DatasetInvLinesPRICEWVAT;
	TIBStringField *DatasetInvLinesDESCRIPTION_SHORT;
	TIBStringField *DatasetInvLinesBARCODE;
	TIBQuery *QrySelProducts;
	TIntegerField *QrySelProductsPRODUCT_ID;
	TIBStringField *QrySelProductsBARCODE;
	TIBStringField *QrySelProductsDESCRIPTION_SHORT;
	TIntegerField *QrySelProductsCAT_ID;
	TIntegerField *QrySelProductsVATCAT_ID;
	TIBBCDField *QrySelProductsBUY_PRICE;
	TIBBCDField *QrySelProductsSELL_PRICE;
	TIBBCDField *QrySelProductsPRICE_WVAT;
	TDateField *QrySelProductsDATE_INSERTED;
	TMemoField *QrySelProductsDESCRIPTION;
	TDataSource *DSDistAim;
	TDataSource *DSDeliveryMethod;
	TDataSource *DSPaymentMeth;
	TDataSource *DSInvTypes;
	TDataSource *DSInvoice;
	TDataSource *DSInvLines;
	TJvPanel *PanelDetails;
	TJvPanel *PanelInvLines;
	TJvDBUltimGrid *GridInvoiceLines;
	TJvPanel *PanelButtons;
	TLabel *Label11;
	TLabel *lblPrice;
	TLabel *Label13;
	TLabel *lblVatValue;
	TLabel *Label12;
	TLabel *lblTotal;
	TLabel *Label16;
	TLabel *Label14;
	TJvDotNetButton *JvDotNetButton2;
	TStatusBar *StatusBar;
	TStringField *DatasetInvoiceCUST_NAME;
	TJvValidateEdit *editDiscPercent;
	TJvValidateEdit *editDiscount;
	TLabel *Label15;
	TLabel *lblEuroSign;
	TJvTimeEdit *editTime;
	TLabel *Label17;
	TIBStringField *QryInvTypesFRM_FILENAME;
	TIBStringField *QryInvTypesEAFDSS_SCRIPT;
	TIntegerField *QryInvTypesINVCOUNT;
	TIntegerField *DatasetInvoiceCONV_INVOICE_ID;
	TTimeField *DatasetInvoiceINVTIME;
	TIBBCDField *DatasetInvLinesQTY;
	TIBBCDField *QrySelProductsRESERVE;
	TIBBCDField *QrySelProductsRESERVE_SECURE;
	TIntegerField *QrySelProductsMETRIC_ID;
	TDateTimeField *QrySelProductsLAST_UPDATE;
	TIBBCDField *QrySelProductsVAT_VALUE;
	TIBBCDField *QrySelCustomerDISCOUNT;
	TIBStringField *QrySelCustomerEMAIL;
	TIBBCDField *DatasetInvLinesDISCOUNT;
	TIBBCDField *DatasetInvLinesVATPERCENT;
	TIBStringField *DatasetInvLinesPRODUCT_DESCR;
	TIBStringField *DatasetInvLinesMETRIC_UNIT;
	TWideMemoField *DatasetInvoiceNOTES_OLD;
	TIBStringField *DatasetInvoiceCITY;
	TIBStringField *DatasetInvoicePOSTCODE;
	TIBStringField *DatasetInvoiceCOUNTRY;
	TIBStringField *DatasetInvoiceNOTES;
	TLabel *Label18;
	TcxDBTextEdit *cxDBTextEdit1;
	TcxDBMemo *cxDBMemo1;
	TLabel *Label1;
	TLabel *Label2;
	TLabel *Label3;
	TLabel *Label5;
	TLabel *Label4;
	TLabel *Label6;
	TLabel *Label7;
	TLabel *Label8;
	TLabel *Label9;
	TLabel *Label10;
	TLabel *Label19;
	TLabel *Label20;
	TJvDotNetEdit *editName;
	TJvDotNetEdit *editVatNo;
	TJvDotNetEdit *editOccupation;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxDBTextEdit *cxDBTextEdit3;
	TcxDBLookupComboBox *comboInvType;
	TcxDBLookupComboBox *comboDistAim;
	TcxDBLookupComboBox *comboDeliveryMethod;
	TcxDBLookupComboBox *comboPaymeth;
	TcxDBDateEdit *cxDBDateEdit1;
	TcxDBDateEdit *cxDBDateEdit2;
	TcxDBTextEdit *cxDBTextEdit4;
	TcxDBTextEdit *cxDBTextEdit5;
	TIntegerField *DatasetInvoiceCODE;
	TIBStringField *DatasetInvLinesNOTES;
	TIBStringField *DatasetInvoiceADDRESS1;
	TIBStringField *DatasetInvoiceADDRESS2;
	void __fastcall editNameKeyDown(TObject *Sender, WORD &Key, TShiftState Shift);
	void __fastcall editVatNoKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall editVatNoKeyPress(TObject *Sender, char &Key);
	void __fastcall editNameChange(TObject *Sender);
	void __fastcall editVatNoChange(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall GridInvoiceLinesKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall DatasetInvLinesAfterPost(TDataSet *DataSet);
	void __fastcall GridInvoiceLinesDblClick(TObject *Sender);
	void __fastcall DatasetInvLinesAfterCancel(TDataSet *DataSet);
	void __fastcall GridInvoiceLinesMouseDown(TObject *Sender, TMouseButton Button,
          TShiftState Shift, int X, int Y);
	void __fastcall GridInvoiceLinesKeyPress(TObject *Sender, char &Key);
	void __fastcall DatasetInvLinesQTYChange(TField *Sender);
	void __fastcall DatasetInvLinesPRICE_PER_ITEMChange(TField *Sender);
	void __fastcall DatasetInvLinesDISCOUNTChange(TField *Sender);
	void __fastcall DatasetInvLinesBeforeEdit(TDataSet *DataSet);
	void __fastcall editDiscPercentKeyPress(TObject *Sender, char &Key);
	void __fastcall editDiscPercentExit(TObject *Sender);
	void __fastcall editDiscountExit(TObject *Sender);
	void __fastcall DatasetInvLinesAfterInsert(TDataSet *DataSet);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
		void __fastcall ActiveControlChanged(TObject *Sender);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall DatasetInvoiceBeforePost(TDataSet *DataSet);
	void __fastcall DatasetInvoiceAfterScroll(TDataSet *DataSet);
private:	// User declarations
	int invoiceId;
	TDate runningDate;
	int CumInvoiceId;
	int selRow, selCol;
	void showData();
	void showLineData();
	bool someFlag;
	void  setProductId(int _prId);
	void setCustomerId(int _id);
	void calcPrices();
	void showSums();
	AnsiString getInvCode();
	bool checkAllFields();
	bool checkFieldsSdap();
	void checkReserve();
	int findCumInvoiceDate(TDate _date);

public:		// User declarations
	__fastcall TFrmEditInvoice(TComponent* Owner, unsigned int _invoiceId);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmEditInvoice *FrmEditInvoice;
//---------------------------------------------------------------------------
#endif
