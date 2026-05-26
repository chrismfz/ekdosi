//---------------------------------------------------------------------------

#ifndef FAutoInvoiceH
#define FAutoInvoiceH
//---------------------------------------------------------------------------
#include "CNewSpecialForm.h"
#include <System.Classes.hpp>
#include <Vcl.Controls.hpp>
#include <Vcl.StdCtrls.hpp>
#include <Vcl.Forms.hpp>
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include <Vcl.ExtCtrls.hpp>
#include "DBAccess.hpp"
#include "JvTimer.hpp"
#include "MemDS.hpp"
#include "Uni.hpp"
#include <Data.DB.hpp>
#include "cxButtons.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLabel.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "JvCheckBox.hpp"
#include "JvExStdCtrls.hpp"
#include <Vcl.Menus.hpp>
#include "JvExControls.hpp"
#include "JvLED.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxMemo.hpp"
#include "cxTextEdit.hpp"
//---------------------------------------------------------------------------

#define INVOICE_EVERY 47
#define MAIL_EVERY 180

class TFrmAutoInvoice : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvPanel *PanelGriniaris;
	TUniQuery *QueryInvoices;
	TJvTimer *TimerInvoicer;
	TJvCheckBox *checkGriniaris;
	TcxLabel *cxLabel1;
	TcxLabel *LblInvoicesLeft;
	TcxLabel *cxLabel2;
	TcxLabel *LblStatus;
	TcxButton *cxButton1;
	TJvLED *LedKeepAlive;
	TUniConnection *sqlConnection;
	TIntegerField *QueryInvoicesuserid;
	TWideMemoField *QueryInvoicesinvoicenum;
	TDateField *QueryInvoicesdate;
	TDateField *QueryInvoicesduedate;
	TDateTimeField *QueryInvoicesdatepaid;
	TDateTimeField *QueryInvoiceslast_capture_attempt;
	TDateTimeField *QueryInvoicesdate_refunded;
	TDateTimeField *QueryInvoicesdate_cancelled;
	TFloatField *QueryInvoicessubtotal;
	TFloatField *QueryInvoicescredit;
	TFloatField *QueryInvoicestax;
	TFloatField *QueryInvoicestax2;
	TFloatField *QueryInvoicestotal;
	TFloatField *QueryInvoicestaxrate;
	TFloatField *QueryInvoicestaxrate2;
	TWideMemoField *QueryInvoicesstatus;
	TWideMemoField *QueryInvoicespaymentmethod;
	TLongWordField *QueryInvoicespaymethodid;
	TWideMemoField *QueryInvoicesnotes;
	TDateTimeField *QueryInvoicescreated_at;
	TDateTimeField *QueryInvoicesupdated_at;
	TSmallintField *QueryInvoicesinvoiced;
	TWideMemoField *QueryInvoicescompanyname;
	TWideMemoField *QueryInvoicesaddress1;
	TWideMemoField *QueryInvoicesaddress2;
	TWideMemoField *QueryInvoicescity;
	TWideMemoField *QueryInvoicesstate;
	TWideMemoField *QueryInvoicespostcode;
	TWideMemoField *QueryInvoicescountry;
	TWideMemoField *QueryInvoicesphonenumber;
	TWideMemoField *QueryInvoicesemail;
	TWideMemoField *QueryInvoicesfirstname;
	TWideMemoField *QueryInvoiceslastname;
	TWideMemoField *QueryInvoicestoinvoice;
	TWideMemoField *QueryInvoicesoccupation;
	TWideMemoField *QueryInvoicesvatno;
	TWideMemoField *QueryInvoicestaxoffice;
	TWideMemoField *QueryInvoicesgkriniaris;
	TSmallintField *QueryInvoicesHAS_3RD_INVOICE;
	TUniQuery *QueryInvLines;
	TIntegerField *QueryInvLinesid;
	TIntegerField *QueryInvLinesuserid;
	TWideStringField *QueryInvLinestype;
	TIntegerField *QueryInvLinesrelid;
	TWideMemoField *QueryInvLinesdescription;
	TFloatField *QueryInvLinesamount;
	TIntegerField *QueryInvLinestaxed;
	TDateField *QueryInvLinesduedate;
	TWideMemoField *QueryInvLinespaymentmethod;
	TWideMemoField *QueryInvLinesnotes;
	TWideStringField *QueryInvLinesalt_company_name;
	TDataSource *DSInvoices;
	TIBQuery *QueryLocalCustomer;
	TIntegerField *QueryLocalCustomerCUST_ID;
	TIntegerField *QueryLocalCustomerALT_CUSTID;
	TIBStringField *QueryLocalCustomerAFM;
	TIBStringField *QueryLocalCustomerNAME;
	TIBStringField *QueryLocalCustomerADDRESS1;
	TIBStringField *QueryLocalCustomerADDRESS2;
	TIBStringField *QueryLocalCustomerCITY;
	TIBStringField *QueryLocalCustomerPOSTCODE;
	TIBStringField *QueryLocalCustomerPHONE1;
	TIBStringField *QueryLocalCustomerPHONE2;
	TIBStringField *QueryLocalCustomerFAX;
	TIBStringField *QueryLocalCustomerOCCUPATION;
	TIBStringField *QueryLocalCustomerTAXOFFICE;
	TWideMemoField *QueryLocalCustomerDETAILS;
	TIBBCDField *QueryLocalCustomerDISCOUNT;
	TIBStringField *QueryLocalCustomerSECONDARY_EMAIL;
	TIBStringField *QueryLocalCustomerEMAIL;
	TIntegerField *QueryLocalCustomerORDER;
	TIBStringField *QueryLocalCustomerCOUNTRY;
	TIntegerField *QueryLocalCustomerPAYMETH_ID;
	TIBStringField *QueryLocalCustomerVAT_VIES;
	TUniQuery *QueryThirdInvoice;
	TIntegerField *QueryThirdInvoicecontactid;
	TIntegerField *QueryThirdInvoiceid;
	TIntegerField *QueryThirdInvoiceuserid;
	TWideStringField *QueryThirdInvoicecompanyname;
	TWideStringField *QueryThirdInvoiceaddress1;
	TWideStringField *QueryThirdInvoiceaddress2;
	TWideStringField *QueryThirdInvoicepostcode;
	TWideStringField *QueryThirdInvoicecity;
	TWideStringField *QueryThirdInvoicephonenumber;
	TWideStringField *QueryThirdInvoicecountry;
	TWideStringField *QueryThirdInvoiceemail;
	TWideStringField *QueryThirdInvoicetaxoffice;
	TWideStringField *QueryThirdInvoiceoccupation;
	TWideStringField *QueryThirdInvoicevatno;
	TWideStringField *QueryThirdInvoicelastname;
	TWideStringField *QueryThirdInvoicefirstname;
	TLargeintField *QueryThirdInvoicecount;
	TBooleanField *QueryThirdInvoiceisReceipt;
	TIBDataSet *DatasetCustomer;
	TIntegerField *DatasetCustomerCUST_ID;
	TIBStringField *DatasetCustomerAFM;
	TIBStringField *DatasetCustomerNAME;
	TIBStringField *DatasetCustomerADDRESS1;
	TIBStringField *DatasetCustomerADDRESS2;
	TIBStringField *DatasetCustomerCITY;
	TIBStringField *DatasetCustomerPOSTCODE;
	TIBStringField *DatasetCustomerOCCUPATION;
	TIBStringField *DatasetCustomerTAXOFFICE;
	TMemoField *DatasetCustomerDETAILS;
	TIBStringField *DatasetCustomerEMAIL;
	TIBBCDField *DatasetCustomerDISCOUNT;
	TIntegerField *DatasetCustomerORDER;
	TIBStringField *DatasetCustomerCOUNTRY;
	TIntegerField *DatasetCustomerALT_CUSTID;
	TIntegerField *DatasetCustomerPAYMETH_ID;
	TIBStringField *DatasetCustomerVAT_VIES;
	TIBStringField *DatasetCustomerSECONDARY_EMAIL;
	TIBStringField *DatasetCustomerFAX;
	TIBStringField *DatasetCustomerPHONE1;
	TIBStringField *DatasetCustomerPHONE2;
	TcxMemo *MemoLog;
	TJvCheckBox *checkMail;
	TIBStringField *DatasetCustomerTYPE;
	TJvCheckBox *checkThird;
	TJvCheckBox *checkAssigned;
	TJvCheckBox *checkMydata;
	TLongWordField *QueryInvoicesid;
	TLongWordField *QueryInvLinesinvoiceid;
	void __fastcall TimerInvoicerTimer(TObject *Sender);
private:	// User declarations
	long lastInvCreateTime;
 long lastMailTime;
	void runInvoicing();
 void sendOneInvoice();
	void syncAllDatasets(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField);
	bool checkCustomerDataIntegrity(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField);
 TUniQuery *QueryCustomerDetails;
//	TUniQuery * getQueryInvoiceDetails(bool &_isInvoice);
	void addNewCustomer(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField);
	void amendCustomer(bool &_isInvoice);
	void invoiceLog(long _invId, AnsiString _message);
	void invoiceAutoSupress(long _invoiceId);
	void markCSInvoice(long _invoiceId, long _mark);
	void log(AnsiString _message);
	long addInvoice(bool _isInvoice);
	long createInvId(bool _isInvoice, AnsiString &_vatNo, AnsiString &_companyName);
 long countInvoiceLines();
	void addInvoiceLines(long _invoiceId);
	long getCurProductIdFromInvlineType();
	double getVatPercent();
	AnsiString getInvFilename(bool _isInvoice);
	void amendInvoiceValues(long _invoiceId);
	void mailInvoices();
	void closeAllDatasets();
	void runGriniaris();
	void runThirdInvoices();
	void runAssignedInvoices();
	void runSendMydata();
	bool sentInvoiceToMyData(int _invoiceId, AnsiString _description);
	void resetInvoicesQuery();
public:		// User declarations
	__fastcall TFrmAutoInvoice(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAutoInvoice *FrmAutoInvoice;
//---------------------------------------------------------------------------
#endif
