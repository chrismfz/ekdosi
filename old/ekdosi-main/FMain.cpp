//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"

//------------ FORMS
#include "FAddCustomer.h"
#include "FDBParams.h"
#include "FShowCustomers.h"
#include "FManageProductCategories.h"
#include "FAddProduct.h"
#include "FManageVatCategories.h"
#include "FShowProducts.h"
#include "FManageInvTypes.h"
#include "FAddInvoice.h"
#include "FManageDeliveryMethods.h"
#include "FManagePaymentMethods.h"
#include "FManageDistributionAim.h"
#include "FReportDesign.h"
#include "FChangeDate.h"
#include "FAddPayment.h"
#include "FInvoiceSend.h"
#include "FShowInvoices.h"
#include "FEditInvoice.h"
#include "FInvoiceReturn.h"
#include "FMetricUnits.h"
#include "FMySqlSync.h"
#include "FManageReports.h"
#include "FShowDuplicates.h"
#include "FPrint.h"
#include "FSelectDate.h"
#include "FShowBalance.h"
#include "FManageCustOrder.h"
#include "FCSConnect.h"
#include "FMailInvoices.h"
#include "FShowMyData.h"
#include "FShowMyDataRemainingInvoices.h"
//#include "FManageCSUsers.h"
//#include "FManageCSInvoices.h"
#include "FAddInvoice2.h"
#include "FAutoInvoice.h"
#include "FAboutOptimum.h"
//------------- END FORMS
#include "RegistryAccess.h"
#include "AppController.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma link "dxSkinBlack"
#pragma link "dxSkinBlue"
#pragma link "dxSkinBlueprint"
#pragma link "dxSkinCaramel"
#pragma link "dxSkinCoffee"
#pragma link "dxSkinDarkRoom"
#pragma link "dxSkinDarkSide"
#pragma link "dxSkinDevExpressDarkStyle"
#pragma link "dxSkinDevExpressStyle"
#pragma link "dxSkinFoggy"
#pragma link "dxSkinGlassOceans"
#pragma link "dxSkinHighContrast"
#pragma link "dxSkiniMaginary"
#pragma link "dxSkinLilian"
#pragma link "dxSkinLiquidSky"
#pragma link "dxSkinLondonLiquidSky"
#pragma link "dxSkinMcSkin"
#pragma link "dxSkinMetropolis"
#pragma link "dxSkinMetropolisDark"
#pragma link "dxSkinMoneyTwins"
#pragma link "dxSkinOffice2007Black"
#pragma link "dxSkinOffice2007Blue"
#pragma link "dxSkinOffice2007Green"
#pragma link "dxSkinOffice2007Pink"
#pragma link "dxSkinOffice2007Silver"
#pragma link "dxSkinOffice2010Black"
#pragma link "dxSkinOffice2010Blue"
#pragma link "dxSkinOffice2010Silver"
#pragma link "dxSkinOffice2013DarkGray"
#pragma link "dxSkinOffice2013LightGray"
#pragma link "dxSkinOffice2013White"
#pragma link "dxSkinOffice2016Colorful"
#pragma link "dxSkinOffice2016Dark"
#pragma link "dxSkinPumpkin"
#pragma link "dxSkinsCore"
#pragma link "dxSkinsDefaultPainters"
#pragma link "dxSkinSeven"
#pragma link "dxSkinSevenClassic"
#pragma link "dxSkinSharp"
#pragma link "dxSkinSharpPlus"
#pragma link "dxSkinSilver"
#pragma link "dxSkinSpringTime"
#pragma link "dxSkinStardust"
#pragma link "dxSkinSummer2008"
#pragma link "dxSkinTheAsphaltWorld"
#pragma link "dxSkinTheBezier"
#pragma link "dxSkinValentine"
#pragma link "dxSkinVisualStudio2013Blue"
#pragma link "dxSkinVisualStudio2013Dark"
#pragma link "dxSkinVisualStudio2013Light"
#pragma link "dxSkinVS2010"
#pragma link "dxSkinWhiteprint"
#pragma link "dxSkinXmas2008Blue"
#pragma link "DBAccess"
#pragma link "Uni"
#pragma resource "*.dfm"
TFrmMain *FrmMain;
//---------------------------------------------------------------------------
__fastcall TFrmMain::TFrmMain(TComponent* Owner)
	: TForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 database->Connected = false;
 LoadingFlag = false;
 reg->getColors(&primary, &secondary, &selectedColor);
 reg->restoreFormPos();
  
 runningDate = Today();

 AppController::setDb(database, IBTransaction1);
 
 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 defaultSQL = new TStringList();
 defaultSQL->AddStrings(DatasetCustomerOrder->SelectSQL);


 delete reg;
}

void TFrmMain::showMessage(AnsiString _message,AnsiString AppName, UINT btnType)
{
 Application->MessageBox(UnicodeString(_message).w_str(),UnicodeString(ApplicationName).w_str(),btnType);
}
//---------------------------------------------------------------------------

void TFrmMain::setDate(TDate date)
{
 runningDate = date;
}

//---------------------------------------------------------------------------

void __fastcall TFrmMain::FormClose(TObject *Sender, TCloseAction &Action)
{
 RegAccess *reg = new RegAccess(this);

 reg->WriteFormPos();

  reg->saveCustomBool("menuShowCustOrder",MenuShowCustOrder->Checked);
 
 delete reg;

 hideCustomerOrder();
 
 Action = caFree;
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::FormShow(TObject *Sender)
{
 RegAccess *reg = new RegAccess(this);

 if(isLocal().Length() > 0)
 {
  delete reg;
  StatusBar->Panels->Items[1]->Text = FormatDateTime("hh:mm",Now());
  connectDb();
  createChildMenus();
  return;
 }
 else if(!reg->DBDataExists())
 {
  showMessage("Δεν έχουν οριστεί οι παράμετροι για την βάση δεδομένων.", ApplicationName, MB_OK | MB_ICONERROR);

  TFrmDBParams *frmDBParams = new TFrmDBParams(this);
  frmDBParams->Show();

  delete reg;
  return;
 }


 StatusBar->Panels->Items[1]->Text = FormatDateTime("hh:mm",Now());
 delete reg;
 connectDb();
 createChildMenus();

 if( MenuShowCustOrder->Checked)
  showCustomerOrder();

// PanelCustOrder->SendToBack();
 // Handle autoinvoice parameter
 if( autoInvoiceEnabled() )
  TFrmAutoInvoice *frmAutoInvoice = new TFrmAutoInvoice(this);

}
//---------------------------------------------------------------------------

void TFrmMain::connectDb()
{
 AnsiString *hostname = new AnsiString();
 AnsiString *path = new AnsiString();
 AnsiString *username = new AnsiString();
 AnsiString *password = new AnsiString();
 RegAccess *regAccess = new RegAccess(this);
 AnsiString localFilename;

 localFilename = isLocal();

 if(database->Connected == true)
  return;

 if(regAccess->DBDataExists())
  regAccess->getDBParams(hostname,path,username,password);

 database->Params->Clear();
  if(localFilename.Length()!=0)
  {
   database->DatabaseName = localFilename;
   database->Params->Add("user_name=sysdba");
   database->Params->Add("password=masterkey");
  }
  else
  {
   if(hostname->Trim().Length()!=0)
     database->DatabaseName = *hostname+":"+*path;
   else
	 database->DatabaseName = *path;
   database->Params->Add(AnsiString("user_name=")+*username);
   database->Params->Add(AnsiString("password=")+*password);
  } 

  try
  {
   database->Connected=true;
  }
  catch(Exception &e)
  {
   StatusBar->Panels->Items[2]->Text = "Χωρίς σύνδεση!";
   showMessage("Η σύνδεση απέτυχε!", "Έκδοση" ,MB_ICONERROR);
  }

 delete hostname, path, username, password, regAccess;
}

AnsiString TFrmMain::isLocal()
{
 if(ParamStr(1)=="-l" || ParamStr(1)=="--local")
 {
  if(FileExists(ParamStr(2)))
   return(ParamStr(2));
  else
   return("");
 }
 else
  return("");
}

bool TFrmMain::autoInvoiceEnabled()
{
 for(int i=1;i<=ParamCount();i++)
 {
  if(LowerCase(ParamStr(i)) == "--autoinvoice")
  {
   return true;
  }
 }
 return false;
}


void TFrmMain::executeConnect()
{

}

void __fastcall TFrmMain::FormCreate(TObject *Sender)
{
 StatusBar->Panels->Items[0]->Width = this->Width - StatusBar->Panels->Items[1]->Width -
  										StatusBar->Panels->Items[2]->Width;
}

void TFrmMain::showClock()
{
 static const AnsiString caption = this->Caption;


 this->Caption = caption + " - "+FormatDateTime(FormatSettings.LongDateFormat,runningDate);
}

TIBDatabase * TFrmMain::getDatabase()
{
 if(database != NULL)
  return(database);
 else
  return(0);
}

TDate TFrmMain::getRunningDate()
{
 return(runningDate);
}
//---------------------------------------------------------------------------

void TFrmMain::disableMenus()
{
 N2->Enabled = false;
 N13->Enabled = false;
 N24->Enabled = false;
 N3->Enabled = false;
 N10->Enabled = false;
 menuReportDesign->Enabled = false;
 N20->Enabled = false;
 N29->Enabled = false;
}
//---------------------------------------------------------------------------

void TFrmMain::enableMenus()
{
 N2->Enabled = true;
 N13->Enabled = true;
 N24->Enabled = true;
 N3->Enabled = true;
 N10->Enabled = true;
 menuReportDesign->Enabled = true;
 N20->Enabled = true;
 N29->Enabled = true;

 if(!SDAP)
 {
  N25->Visible = false;
  N31->Visible = false;
 }
 if(!CSCART_SYNC)
 {
  CSCart1->Visible = false;
  N34->Visible = false;
 }
 if(!PROTIMOLOGIO)
 {
  N33->Visible = false;
  CSUsers1->Visible = false;
  CSInvoices1->Visible = false;
 }
}

void TFrmMain::createChildMenus()
{
 TIBQuery *query = new TIBQuery(this);

 query->Database = database;
 query->Transaction = IBTransaction1;

 N10->Remove(MNewInvoice2);
 N10->Remove(MNewInvoice);
 N10->Remove(NDash);
 N10->Clear();
 N10->Insert(0,MNewInvoice2);
 N10->Insert(1,MNewInvoice);
 N10->Insert(2,NDash);
 
 query->SQL->SetText(UnicodeString("SELECT * FROM INVTYPE WHERE SHOW_ON_MENU = 1 ORDER BY INVTYPE_ID").w_str());
 query->Open();
 
 query->Last();
 int recordCount = query->RecordCount;
 query->First();
 for(int i=0;i<recordCount;i++)
 {
  TMenuItem *item = new TMenuItem(MNewInvoice);

  N10->Insert(N10->Count,item);
  item->Caption = query->FieldByName("NAME")->AsString;
  item->OnClick = this->executeAddInvoice;
  query->Next();
 }
 TMenuItem *itemDash = new TMenuItem(MNewInvoice);
 N10->Insert(N10->Count,itemDash);
 itemDash->Caption = "-";

 query->SQL->Clear();
 query->SQL->SetText(UnicodeString("SELECT INVTYPE.*, CUSTOMER.NAME AS CUSTNAME FROM INVTYPE INNER JOIN CUSTOMER ON INVTYPE.CUST_ID = CUSTOMER.CUST_ID WHERE SHOW_ON_MENU = 1 AND INVTYPE.CUST_ID IS NOT NULL ORDER BY INVTYPE_ID").w_str());
 query->Open();
 query->Last();
 recordCount = query->RecordCount;
 query->First();
 for(int i=0;i<recordCount;i++)
 {
  TMenuItem *item = new TMenuItem(MNewInvoice);

  N10->Insert(N10->Count,item);
  item->Caption = query->FieldByName("NAME")->AsString+" "+query->FieldByName("CUSTNAME")->AsString;
  item->OnClick = this->executeAddInvCustId;
  query->Next();
 }

 query->Close();
 createChildMenuReport();
 delete query;
}

void TFrmMain::createChildMenuReport()
{
 TIBQuery *query = new TIBQuery(this);

 N4->Remove(menuReportDesign);
 N4->Clear();
 query->Database = database;
 query->Transaction = IBTransaction1;

 query->SQL->SetText(UnicodeString("SELECT * FROM REPORTS WHERE SHOW_ON_MENU = 1 ORDER BY REPORT_ID").w_str());
 query->Open();

 query->Last();
 int recordCount = query->RecordCount;
 query->First();
 for(int i=0;i<recordCount;i++)
 {
  TMenuItem *item = new TMenuItem(MNewInvoice);

  N4->Insert(i,item);
  item->Caption = query->FieldByName("DESCRIPTION")->AsString;
  item->OnClick = this->openReport;
  query->Next();
 }

 //create dash menu item
 TMenuItem *dash = new TMenuItem(MNewInvoice);
 N4->Insert(N4->Count,dash);
 dash->Caption = "-";

 TMenuItem *showReportManage = new TMenuItem(MNewInvoice);
 N4->Insert(N4->Count,showReportManage);
 showReportManage->Caption = "Διαχείριση";
 showReportManage->OnClick = showReportManager;

 N4->Insert(N4->Count,menuReportDesign);
 delete query;
}

void TFrmMain::showCustomerOrder()
{
 if(!MenuShowCustOrder->Checked)
  return;
 Splitter1->Show();
 DatasetCustomerOrder->Open();
 PanelCustOrder->Visible = true;




 lblCaptionName->Show();
 lblCaptionAddress->Show();
 lblCaptionPhone->Show();
 lblName->Show();
 lblAddress->Show();
 lblTelephone->Show();


}

void TFrmMain::hideCustomerOrder()
{
 if(MenuShowCustOrder->Checked)
  return;

 DatasetCustomerOrder->Close();
 PanelCustOrder->Visible = false;


 Splitter1->Hide();
 lblCaptionName->Hide();
 lblCaptionAddress->Hide();
 lblCaptionPhone->Hide();
 lblName->Hide();
 lblAddress->Hide();
 lblTelephone->Hide();
}

AnsiString TFrmMain::findInvType(int custId)
{
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = IBTransaction1;

 query->SQL->Add(UnicodeString("SELECT COUNT(*) AS CNT,INVTYPE.INVTYPE_ID FROM INVTYPE INNER JOIN INVOICE ON INVTYPE.INVTYPE_ID = INVOICE.INVTYPE\
					WHERE INVOICE.CUST_ID = :CUST_ID \
						GROUP BY  INVTYPE.INVTYPE_ID \
						ORDER BY \"CNT\" DESC").w_str());
 query->ParamByName("CUST_ID")->AsInteger = custId;
 query->Open();

 AnsiString invType;
 if(query->RecordCount == 0)
  invType = "";
 else
  invType = query->FieldByName("INVTYPE_ID")->AsString;

 delete query;

 return(invType);
}

//---------------------------------------------------------------------------

void __fastcall TFrmMain::openReport(TObject *Sender)
{
 TMenuItem *item = (TMenuItem *)Sender;
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->SQL->SetText(UnicodeString("SELECT * FROM REPORTS WHERE SHOW_ON_MENU = 1 ORDER BY REPORT_ID").w_str());
 query->Open();

 int queryIterator = 0;

 while(queryIterator < item->MenuIndex)
 {
  query->Next();
  queryIterator++;
 }

 if(query->FieldByName("FILENAME")->AsString.Length() == 0 || !FileExists(query->FieldByName("FILENAME")->AsString))
 {
  return;
 }

 TIBTransaction *trns = new TIBTransaction(this);
 trns->DefaultDatabase = database;
 TFrmPrint *frmPrint = new TFrmPrint(this, trns, query->FieldByName("REPORT_ID")->AsInteger);

 delete query;
}

void __fastcall TFrmMain::databaseAfterConnect(TObject *Sender)
{
 AnsiString textConntected = "Ενεργή σύνδεση";
 StatusBar->Panels->Items[2]->Width = TextWidth(textConntected)+35;

 StatusBar->Panels->Items[2]->Text = textConntected;
 enableMenus();
 RegAccess *reg = new RegAccess(this);
 if(reg->loadCustomBool("menuShowCustOrder"))
  MenuShowCustOrder->Click();
 delete reg;
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::databaseAfterDisconnect(TObject *Sender)
{
 AnsiString textConntected = "Ανενεργή σύνδεση";
 StatusBar->Panels->Items[2]->Width = TextWidth(textConntected)+35 ;

 StatusBar->Panels->Items[2]->Text = textConntected;
 disableMenus();
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::FormResize(TObject *Sender)
{
 StatusBar->Panels->Items[0]->Width =
				 StatusBar->Width - StatusBar->Panels->Items[1]->Width -
				 StatusBar->Panels->Items[2]->Width;
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::Timer1Timer(TObject *Sender)
{
 showClock();
 if(FormatDateTime("ss",Now()).ToInt() % 5 ==0)
   StatusBar->Panels->Items[1]->Text = FormatDateTime("hh:mm",Now());
}
//---------------------------------------------------------------------------
void __fastcall TFrmMain::mnuNewCustomerClick(TObject *Sender)
{
 TFrmAddCustomer *frmAddCustomer = new TFrmAddCustomer(this);
}

void __fastcall TFrmMain::mnuShowCustomersClick(TObject *Sender)
{
 TFrmShowCustomers *frmShowCustomers = new TFrmShowCustomers(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N23Click(TObject *Sender)
{
 TFrmManPrCateg *frmPrCategories = new TFrmManPrCateg(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N21Click(TObject *Sender)
{
 TFrmAddProduct *frmAddProduct = new TFrmAddProduct(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N12Click(TObject *Sender)
{
 TFrmManageVatCategories *frmManageVatCats = new TFrmManageVatCategories(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N22Click(TObject *Sender)
{
 TFrmShowProducts *frmShowProducts = new TFrmShowProducts(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::MParametersClick(TObject *Sender)
{
 TFrmDBParams *frmDbParams = new TFrmDBParams(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::MCalendarClick(TObject *Sender)
{
 TFrmManageInvTypes *frmManInvTypes = new TFrmManageInvTypes(this); 	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::MNewInvoiceClick(TObject *Sender)
{
 TFrmAddInvoice *frmAddInvoice = new TFrmAddInvoice(this,"");
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N16Click(TObject *Sender)
{
 TFrmManageDeliveryMeth *frmManageDeliveryM = new TFrmManageDeliveryMeth(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N17Click(TObject *Sender)
{
 TFrmManagePaymentMeth *frmManagePaymentMeth = new TFrmManagePaymentMeth(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N18Click(TObject *Sender)
{
 TFrmManageDistAim *frmManageDistAim = new TFrmManageDistAim(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::menuReportDesignClick(TObject *Sender)
{
 TFrmReportDesign *frmDesign = new TFrmReportDesign(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N20Click(TObject *Sender)
{
 TFrmChangeDate *frmChangeDate = new TFrmChangeDate(this);
}
//---------------------------------------------------------------------------



void __fastcall TFrmMain::N9Click(TObject *Sender)
{
 Close();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N6Click(TObject *Sender)
{
 database->Connected = true;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N7Click(TObject *Sender)
{
 database->Connected = false;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 database->Connected = false;

 RegAccess *reg = new RegAccess(this);

 delete reg;

 TcxGridStorageOptions Opts;
 ViewCustOrder->StoreToRegistry(ApplicationName,false, Opts, ViewCustOrder->Name);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N27Click(TObject *Sender)
{
 TFrmAddPayment *frmAddPayment = new TFrmAddPayment(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N25Click(TObject *Sender)
{
 TFrmInvoiceSend *frmInvoiceSend = new TFrmInvoiceSend(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N30Click(TObject *Sender)
{
 TFrmShowInvoices *frmShowInvoices = new TFrmShowInvoices(this);	
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::N31Click(TObject *Sender)
{
 TFrmInvoiceReturn *frmInvoiceReturn = new TFrmInvoiceReturn(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::databaseBeforeConnect(TObject *Sender)
{
 if(LoadingFlag)
 {
  database->Connected = false;
  LoadingFlag = false;
 }
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::N28Click(TObject *Sender)
{
 TFrmMetricUnits *frmMetricUnit = new TFrmMetricUnits(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::CSCart1Click(TObject *Sender)
{
// TFrmMySqlSync *frmMySqlSync = new TFrmMySqlSync(this);
}
//---------------------------------------------------------------------------

//TODO::
void  __fastcall TFrmMain::executeAddInvoice(TObject *Sender)
{
 TIBQuery *query = new TIBQuery(this);
 TMenuItem *item = (TMenuItem *)Sender;

 query->Database = database;
 query->Transaction = IBTransaction1;

 query->SQL->Add("SELECT * FROM INVTYPE WHERE SHOW_ON_MENU = 1 ORDER BY INVTYPE_ID");
 query->Open();

 int i=0;
 while(i < (item->MenuIndex-2) )
 {
  query->Next();
  i++;
 }

 TFrmAddInvoice *frmAddInvoice = new TFrmAddInvoice(this, query->FieldByName("INVTYPE_ID")->AsString);

 delete query;
}

void __fastcall TFrmMain::executeAddInvCustId(TObject *Sender)
{
 TIBQuery *query = new TIBQuery(this);
 TMenuItem *item = (TMenuItem *)Sender;

 query->Database = database;
 query->Transaction = IBTransaction1;
 query->SQL->SetText(UnicodeString("SELECT * FROM INVTYPE WHERE SHOW_ON_MENU = 1 ORDER BY INVTYPE_ID").w_str());
 query->Open();
 query->Last();
 int recordCount = query->RecordCount;
 query->Close();

 query->SQL->SetText(UnicodeString("SELECT INVTYPE.*, CUSTOMER.NAME AS CUSTNAME FROM INVTYPE INNER JOIN CUSTOMER ON INVTYPE.CUST_ID = CUSTOMER.CUST_ID WHERE SHOW_ON_MENU = 1 AND INVTYPE.CUST_ID IS NOT NULL ORDER BY INVTYPE_ID").w_str() );
 query->Open();

 int i=0;
 while(i < (item->MenuIndex-3-recordCount) )
 {
  query->Next();
  i++;
 }

 TFrmAddInvoice *frmAddInvoice = new TFrmAddInvoice(this, query->FieldByName("INVTYPE_ID")->AsString);

 frmAddInvoice->setCustomerId(query->FieldByName("CUST_ID")->AsInteger);
 delete query;
}

void __fastcall TFrmMain::showReportManager(TObject *Sender)
{
 TFrmManageReports *frmManageReports = new TFrmManageReports(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N34Click(TObject *Sender)
{
 TFrmShowDuplicates *frmShowDuplicates  = new TFrmShowDuplicates(this);	
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::N11Click(TObject *Sender)
{
 TFrmShowBalance *frmShowBalance = new TFrmShowBalance(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::MenuShowCustOrderClick(TObject *Sender)
{
 if(MenuShowCustOrder->Checked)
  showCustomerOrder();
 else
  hideCustomerOrder();
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N19Click(TObject *Sender)
{
 TFrmManageCustOrder *frmCustShowOrder = new TFrmManageCustOrder(this);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::DatasetCustomerOrderAfterScroll(TDataSet *DataSet)
{
 lblName->Caption = DatasetCustomerOrder->FieldByName("NAME")->AsString;
 lblAddress->Caption = DatasetCustomerOrder->FieldByName("CONCATENATION")->AsString;
 lblTelephone->Caption = DatasetCustomerOrder->FieldByName("PHONE")->AsString;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::DatasetCustomerOrderBeforeOpen(TDataSet *DataSet)
{
 DatasetCustomerOrder->ParamByName("DT")->AsDate = runningDate;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::editSearchChange(TObject *Sender)
{

 TStringList *OrderByList = new TStringList();
 TIBDataSet *dataset = DatasetCustomerOrder;

 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-2]);
 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1]);

 dataset->SelectSQL->Clear();
 for(int i =0;i<defaultSQL->Count-2;i++)
  dataset->SelectSQL->Add(defaultSQL->Strings[i]);

 if(editSearch->Text.Trim().Length() >0 )
 {
  dataset->SelectSQL->Add("WHERE");
  dataset->SelectSQL->Add("NAME CONTAINING '"+editSearch->Text+"'");
  dataset->SelectSQL->Add("OR ADDRESS1 CONTAINING '"+editSearch->Text+"'");
  dataset->SelectSQL->Add("OR ADDRESS2 CONTAINING '"+editSearch->Text+"'");
 }

 if(dataset->SelectSQL->Count > 0 &&
			(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1] == AnsiString("WHERE") ||
			dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1] == "AND"))
 dataset->SelectSQL->Delete(dataset->SelectSQL->Count-1);

 dataset->SelectSQL->Add(OrderByList->Strings[0]);
 dataset->SelectSQL->Add(OrderByList->Strings[1]);
 //debuging
// Memo1->Lines = DatasetCustomer->SelectSQL;
 dataset->Active = false;
 dataset->Active = true;

 delete OrderByList;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::LabelExMouseEnter(TObject *Sender)
{
 LabelEx->Font->Color = clRed;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::LabelExMouseLeave(TObject *Sender)
{
 LabelEx->Font->Color = clWindowText;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::LabelExClick(TObject *Sender)
{
 editSearch->Clear();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::ViewCustOrderDblClick(TObject *Sender)
{
 TFrmAddInvoice *frmInvoice = new TFrmAddInvoice(this,findInvType(DatasetCustomerOrder->FieldByName("CUST_ID")->AsInteger));

 frmInvoice->setCustomerId(DatasetCustomerOrder->FieldByName("CUST_ID")->AsInteger);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::PanelCustOrderResize(TObject *Sender)
{
 int left =  PanelCustOrder->Width + 30;
 lblCaptionName->Left = left;
 lblCaptionAddress->Left = left;
 lblCaptionPhone->Left = left;
 lblName->Left = left+ 100;
 lblAddress->Left = left+100;
 lblTelephone->Left = left+100;
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N33Click(TObject *Sender)
{
 TFrmCSConnect *frmCSConnect = new TFrmCSConnect(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::CSUsers1Click(TObject *Sender)
{
// TFrmManageCSUsers *frmManageCSUsers = new TFrmManageCSUsers(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::CSInvoices1Click(TObject *Sender)
{
// TFrmManageCSInvoices *frmManageCSInvoices = new TFrmManageCSInvoices(this);
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::MNewInvoice2Click(TObject *Sender)
{
 TFrmAddInvoice2 *frmAddInvoice = new TFrmAddInvoice2(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N35Click(TObject *Sender)
{
 TFrmShowMyData *frmShowMyData = new TFrmShowMyData(this);
}
//---------------------------------------------------------------------------


void __fastcall TFrmMain::menuRemainingMyDataClick(TObject *Sender)
{
	TFrmShowMyDataRemainingInvoices *frmShowRemainingInvoices = new TFrmShowMyDataRemainingInvoices(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::N36Click(TObject *Sender)
{
 TFrmAutoInvoice *frmAutoInvoice = new TFrmAutoInvoice(this);
}
//---------------------------------------------------------------------------

void __fastcall TFrmMain::AboutOptimum1Click(TObject *Sender)
{
	TFrmAboutOptimum *frmAbout = new TFrmAboutOptimum(this);
	frmAbout->ShowModal();
}
//---------------------------------------------------------------------------

