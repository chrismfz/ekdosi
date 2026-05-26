//---------------------------------------------------------------------------

#ifndef FCSConnectH
#define FCSConnectH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>


#include "CNewSpecialForm.h"
#include "FAddCustomer.h"
#include <DB.hpp>
#include <FMTBcd.hpp>
#include <SqlExpr.hpp>
#include <WideStrings.hpp>
#include "cxStyles.hpp"
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
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include <ExtCtrls.hpp>
#include "JvComCtrls.hpp"
#include "JvExComCtrls.hpp"
#include "JvRollOut.hpp"
#include <ComCtrls.hpp>
#include "JvDBDotNetControls.hpp"
#include <DBCtrls.hpp>
#include <Mask.hpp>
#include "cxContainer.hpp"
#include "cxDBEdit.hpp"
#include "cxTextEdit.hpp"
#include "cxDBLookupComboBox.hpp"
#include "cxDBLookupEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxLookupEdit.hpp"
#include "cxMaskEdit.hpp"
#include "JvExControls.hpp"
#include "JvLabel.hpp"
#include "JvTimer.hpp"
#include "JvToolBar.hpp"
#include <ImgList.hpp>
#include <ToolWin.hpp>
#include "JvExStdCtrls.hpp"
#include "JvRadioButton.hpp"
#include "cxCalc.hpp"
#include "cxCheckBox.hpp"
#include <Datasnap.DBClient.hpp>
#include <SimpleDS.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExDBGrids.hpp"
#include <Vcl.DBGrids.hpp>
#include <Vcl.Grids.hpp>
#include <Data.DBXOdbc.hpp>
#include "JvADOQuery.hpp"
#include <Data.Win.ADODB.hpp>
#include "DBAccess.hpp"
#include "Uni.hpp"
#include "MemDS.hpp"
#include "MySQLUniProvider.hpp"
#include "UniProvider.hpp"
#include "cxNavigator.hpp"
#include <System.ImageList.hpp>
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"

//---------------------------------------------------------------------------
class TFrmCSConnect : public NewSpecialForm
{
__published:	// IDE-managed Components
	TDataSource *DSInvoices;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TJvPanel *PanelMain;
	TcxGrid *GridCSInvoices;
	TcxGridDBTableView *ViewCSInvoice;
	TcxGridLevel *GridCSInvoicessLevel1;
	TJvRollOut *RollDetail;
	TJvPageControl *PageControl;
	TTabSheet *TabSheet1;
	TLabel *Label1;
	TLabel *Label2;
	TLabel *Label3;
	TcxDBTextEdit *cxDBTextEdit1;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxDBTextEdit *cxDBTextEdit3;
	TcxDBTextEdit *cxDBTextEdit4;
	TLabel *Label4;
	TcxDBTextEdit *cxDBTextEdit5;
	TLabel *Label5;
	TcxDBTextEdit *cxDBTextEdit6;
	TLabel *Label6;
	TcxDBTextEdit *cxDBTextEdit7;
	TLabel *Label7;
	TcxDBTextEdit *cxDBTextEdit8;
	TcxDBTextEdit *cxDBTextEdit9;
	TLabel *Label8;
	TIBQuery *QueryLocalCustomer;
	TDataSource *DSLocalCustomer;
	TcxDBTextEdit *cxDBTextEdit10;
	TLabel *Label9;
	TTabSheet *TabSheet2;
//	TmySQLQuery *QueryInvLines;
	TDataSource *DSInvLines;
	TcxGrid *GridCSInvoiceLines;
	TcxGridDBTableView *ViewInvoiceLines;
	TcxGridLevel *Level1InvoiceLines;
	TcxGridDBColumn *ViewInvoiceLinestype;
	TcxGridDBColumn *ViewInvoiceLinesrelid;
	TcxGridDBColumn *ViewInvoiceLinesdescription;
	TcxGridDBColumn *ViewInvoiceLinesamount;
	TButton *cmdSuppress;
	TButton *cmdInvoice;
	TButton *Button2;
	TButton *cmdCustomerAdd;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TJvRadioButton *radioUnInvoiced;
	TJvRadioButton *radioSuppressed;
	TJvRadioButton *JvRadioButton1;
	TButton *cmdSearchInvoice;
	TcxCalcEdit *cxCalcEdit1;
	TJvRadioButton *JvRadioButton2;
	TButton *btnShowCust;
	TButton *Button4;
	TUniConnection *sqlConnection;
	TUniQuery *QueryInvoices;
	TcxGridDBColumn *ViewCSInvoiceid;
	TcxGridDBColumn *ViewCSInvoiceuserid;
	TcxGridDBColumn *ViewCSInvoicedatepaid;
	TcxGridDBColumn *ViewCSInvoicetotal;
	TcxGridDBColumn *ViewCSInvoicefirstname;
	TcxGridDBColumn *ViewCSInvoicelastname;
	TMySQLUniProvider *MySQLUniProvider1;
	TUniQuery *DatasetInvoices;
	TUniQuery *QueryInvLines;
	TcxGridDBColumn *ViewCSInvoicecompanyname;
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
	TcxCheckBox *checkEmail;
	TcxTextEdit *EditFilter;
	TcxGridDBColumn *ViewCSInvoicegkriniaris;
	TcxGridDBColumn *ViewCSInvoiceinvoiced;
	TcxStyle *cxStyle1;
	TWideStringField *QueryInvLinesalt_company_name;
	TcxGridDBColumn *ViewInvoiceLinesalt_company_name;
	TTabSheet *TabSheet3;
	TcxGridDBColumn *ViewCSInvoiceHAS_3RD_INVOICE;
	TJvLabel *lblInvoiceThird;
	TToolButton *ToolRefresh1;
	TLabel *Label10;
	TcxDBTextEdit *cxDBTextEdit11;
	TcxDBTextEdit *cxDBTextEdit12;
	TLabel *Label11;
	TUniQuery *QueryThirdInvoice;
	TDataSource *DSThirdInvoice;
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
	TBooleanField *QueryThirdInvoiceisReceipt;
	TIBQuery *QueryAcceptedCustomer;
	TIntegerField *QueryAcceptedCustomerCUST_ID;
	TIBStringField *QueryAcceptedCustomerAFM;
	TIBStringField *QueryAcceptedCustomerNAME;
	TIBStringField *QueryAcceptedCustomerADDRESS1;
	TIBStringField *QueryAcceptedCustomerADDRESS2;
	TIBStringField *QueryAcceptedCustomerCITY;
	TIBStringField *QueryAcceptedCustomerPOSTCODE;
	TIBStringField *QueryAcceptedCustomerPHONE1;
	TIBStringField *QueryAcceptedCustomerPHONE2;
	TIBStringField *QueryAcceptedCustomerFAX;
	TIBStringField *QueryAcceptedCustomerOCCUPATION;
	TIBStringField *QueryAcceptedCustomerTAXOFFICE;
	TWideMemoField *QueryAcceptedCustomerDETAILS;
	TIBBCDField *QueryAcceptedCustomerDISCOUNT;
	TIBStringField *QueryAcceptedCustomerEMAIL;
	TIBStringField *QueryAcceptedCustomerCOUNTRY;
	TIntegerField *QueryAcceptedCustomerALT_CUSTID;
	TIntegerField *QueryAcceptedCustomerPAYMETH_ID;
	TIBStringField *QueryAcceptedCustomerVAT_VIES;
	TIBStringField *QueryAcceptedCustomerSECONDARY_EMAIL;
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
	TIntegerField *DatasetInvoicesid;
	TIntegerField *DatasetInvoicesuserid;
	TWideMemoField *DatasetInvoicesinvoicenum;
	TDateField *DatasetInvoicesdate;
	TDateField *DatasetInvoicesduedate;
	TDateTimeField *DatasetInvoicesdatepaid;
	TDateTimeField *DatasetInvoiceslast_capture_attempt;
	TDateTimeField *DatasetInvoicesdate_refunded;
	TDateTimeField *DatasetInvoicesdate_cancelled;
	TFloatField *DatasetInvoicessubtotal;
	TFloatField *DatasetInvoicescredit;
	TFloatField *DatasetInvoicestax;
	TFloatField *DatasetInvoicestax2;
	TFloatField *DatasetInvoicestotal;
	TFloatField *DatasetInvoicestaxrate;
	TFloatField *DatasetInvoicestaxrate2;
	TWideMemoField *DatasetInvoicesstatus;
	TWideMemoField *DatasetInvoicespaymentmethod;
	TLongWordField *DatasetInvoicespaymethodid;
	TWideMemoField *DatasetInvoicesnotes;
	TDateTimeField *DatasetInvoicescreated_at;
	TDateTimeField *DatasetInvoicesupdated_at;
	TSmallintField *DatasetInvoicesinvoiced;
	TWideMemoField *DatasetInvoicescompanyname;
	TWideMemoField *DatasetInvoicesaddress1;
	TWideMemoField *DatasetInvoicesaddress2;
	TWideMemoField *DatasetInvoicescity;
	TWideMemoField *DatasetInvoicesstate;
	TWideMemoField *DatasetInvoicespostcode;
	TWideMemoField *DatasetInvoicescountry;
	TWideMemoField *DatasetInvoicesphonenumber;
	TWideMemoField *DatasetInvoicesemail;
	TWideMemoField *DatasetInvoicesfirstname;
	TWideMemoField *DatasetInvoiceslastname;
	TWideMemoField *DatasetInvoicestoinvoice;
	TWideMemoField *DatasetInvoicesoccupation;
	TWideMemoField *DatasetInvoicesvatno;
	TWideMemoField *DatasetInvoicestaxoffice;
	TLargeintField *DatasetInvoicesHAS_3RD_INVOICE;
	TJvRadioButton *JvRadioButton3;
	TButton *Button1;
	TLongWordField *QueryInvoicesHAS_3RD_INVOICE;
	TLongWordField *QueryInvoicesid;
	TLongWordField *QueryInvLinesinvoiceid;
	void __fastcall QueryInvoicesAfterScroll(TDataSet *DataSet);
	void __fastcall btnConnectClick(TObject *Sender);
	void __fastcall cmdSuppressClick(TObject *Sender);
	void __fastcall cmdCustomerAddClick(TObject *Sender);
	void __fastcall cmdInvoiceClick(TObject *Sender);
	void __fastcall ToolRefresh1Click(TObject *Sender);
	void __fastcall Button2Click(TObject *Sender);
	void __fastcall radioUnInvoicedClick(TObject *Sender);
	void __fastcall radioSuppressedClick(TObject *Sender);
	void __fastcall JvRadioButton1Click(TObject *Sender);
	void __fastcall JvRadioButton2Click(TObject *Sender);
	void __fastcall cmdSearchInvoiceClick(TObject *Sender);
	void __fastcall btnShowCustClick(TObject *Sender);
	void __fastcall Button4Click(TObject *Sender);
	void __fastcall QueryLocalCustomerAfterOpen(TDataSet *DataSet);
	void __fastcall FormClose(TObject *Sender, TCloseAction &Action);
	void __fastcall EditFilterPropertiesChange(TObject *Sender);
	void __fastcall cxCalcEdit1KeyDown(TObject *Sender, WORD &Key, TShiftState Shift);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall JvRadioButton3Click(TObject *Sender);
	void __fastcall Button1Click(TObject *Sender);


private:	// User declarations
	void prepareProducts();
	int returnChecked();
	AnsiString findInvType();
	int invId;
    bool formVisible;
	void mailInvoice(int _invoiceId);
	void updateCustomerDetails();
	TFrmAddCustomer* addCustomer();
	bool isInvoiceThirdSelected();
	void refreshDataset(TUniQuery *_dataset);

public:		// User declarations
	__fastcall TFrmCSConnect(TComponent* Owner);
	void setCustomerId(int _id);
	void setCreatedInvId(int _invoiceId);
	void setAllCreatedInvsId(int _invoiceId);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmCSConnect *FrmCSConnect;
//---------------------------------------------------------------------------
#endif
