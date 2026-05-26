//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FShowCustomers.h"
#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"
#include "FEditInvoice.h"
#include "FAddPayment.h"
#include "FAddInvoice.h"
#include "FMailInvoices.h"
#include "FPrint.h"
#include "AppController.h"
#include <vector>
#include "FShowInvoices.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxContainer"
#pragma link "cxDBEdit"
#pragma link "cxDBLookupComboBox"
#pragma link "cxDBLookupEdit"
#pragma link "cxDropDownEdit"
#pragma link "cxLookupEdit"
#pragma link "cxMaskEdit"
#pragma link "cxMemo"
#pragma link "cxNavigator"
#pragma link "cxRadioGroup"
#pragma link "cxTextEdit"
#pragma link "cxButtons"
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
#pragma link "cxCheckBox"
#pragma resource "*.dfm"
TFrmShowCustomers *FrmShowCustomers;

//---------------------------------------------------------------------------
__fastcall TFrmShowCustomers::TFrmShowCustomers(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 btnAdd->Left = PanelSearch->Width - 2 - btnAdd->Width;
 
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);
 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;


 //Point to the main dataset
 dataset = DatasetCustomers;


 DatasetCustomers->Open();

 QueryOccupation->Open();
 QueryTaxOffice->Open();
 QueryCity->Open();
 QueryCountry->Open();
 QueryPaymentMethod->Open();

 QueryInvoice->Open();
 DatasetPayment->Open();


 //Search system
 defaultSQL = new TStringList();

 defaultSQL->AddStrings(DatasetCustomers->SelectSQL);

 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 10);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setField("NAME AFM", "Εύρεση:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);
 
 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;


 //Set the height of the search panel
 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;

 PageControl->ActivePageIndex = 0;
}
//---------------------------------------------------------------------------


void TFrmShowCustomers::locateCust(int _id)
{
 if(DatasetCustomers->Active==true)
 {
  TLocateOptions options;
  options << loPartialKey;
  DatasetCustomers->Locate("CUST_ID",AnsiString(_id), options);
 }
}

void __fastcall TFrmShowCustomers::btnMinusClick(TObject *Sender)
{
 unsigned int pos = ((((TWinControl *)Sender)->Top -SEARCH_VERTL_SPC) / SEARCH_SPC_BTWN);
 bool refreshDataset = false;

 vector<EditBox *>::iterator w;
 w = editBoxes.begin();
 
 for(int i=0;i < pos;i++)
   w++;

 if((*w)->getFilter().Length() > 0)
  refreshDataset = true;

 EditBox *tmpBox = *w;
 tmpBox->Hide();
 editBoxes.erase(w);
 garbage.push_back(tmpBox);

 if(refreshDataset)
  editSearchChange(Sender);

 if(editBoxes.size() == 0)
  return;

 w = editBoxes.begin();
 unsigned int i = 0;
 while( w != editBoxes.end())
 {
  (*w)->setTop((i * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC);
  i++;
  w++;
 }

 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;
}


void __fastcall TFrmShowCustomers::ToolEditClick(TObject *Sender)
{
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
 
 DBEditName->SetFocus();
 lblCheck->Visible = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::editSearchChange(TObject *Sender)
{
 vector<EditBox *>::iterator w;

 TStringList *OrderByList = new TStringList();

 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-2]);
 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1]);

 dataset->SelectSQL->Clear();
 for(int i =0;i<defaultSQL->Count-2;i++)
  dataset->SelectSQL->Add(defaultSQL->Strings[i]);
 
 w = editBoxes.begin();
 dataset->SelectSQL->Add("WHERE");
 while( w != editBoxes.end() )
 {
  if((*w)->getFilter().Length() > 0)
   dataset->SelectSQL->Add((*w)->getFilter());
  else
  {
   w++;
   continue;
  }
  w++;
  if(w != editBoxes.end())
   dataset->SelectSQL->Add("AND");
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


void __fastcall TFrmShowCustomers::JvDotNetDBEdit4KeyPress(TObject *Sender,
      char &Key)
{
 if( (Key < '0' || Key > '9') && Key != 8)
  Key = 0;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 lblCheck->Visible = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolAcceptClick(TObject *Sender)
{
 if(DBEditName->Text.Trim().Length() == 0 )
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά (κίτρινα) πεδία",ApplicationName, MB_ICONERROR);
  return;
 }

 if(dataset->State == dsInsert && checkVatExists(dataset->FieldByName("AFM")->AsString))
 {
  int answer = showMessage("Το ΑΦΜ υπάρχει! Είστε σίγουρος ότι θέλετε να συνεχίσετε;",ApplicationName, MB_ICONINFORMATION | MB_YESNO);
  if(answer != 6)
  {
   editVatNo->SetFocus();
   return;
  }
 }

 dataset->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 lblCheck->Visible = false;
}
//---------------------------------------------------------------------------

bool TFrmShowCustomers::checkVatExists(AnsiString _vatNumber)
{
 TIBQuery *query = getNewQuery();
 bool retVal=false;

 query->SQL->Text = "SELECT * FROM CUSTOMER WHERE AFM = :AFM";
 query->ParamByName("AFM")->AsString = _vatNumber.Trim();
 query->Open();

 if(query->RecordCount > 0)
  retVal = true;

 delete query;
 return(retVal);

}

void __fastcall TFrmShowCustomers::ToolDeleteClick(TObject *Sender)
{
 if(dataset->RecordCount==0)
  return;

 try
 {
  dataset->Delete();
 }
 catch(Exception &e)
 {
  Application->ShowException(&e);
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolPAddClick(TObject *Sender)
{
 JvDotNetButton2Click(ToolAddPayments);
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::editPercentExit(TObject *Sender)
{
 if(editPercent->Text.Length() == 0)
  return;
if(editPercent->Text.ToInt() <0 || editPercent->Text.ToInt() >100 )
 {
  showMessage("Παρακαλώ εισάγεται ποσοστό επι τοις εκατό.",ApplicationName, MB_ICONERROR);
  editPercent->SetFocus();
  editPercent->SelectAll();
 }		
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
vector<EditBox *>::iterator w;
 w = garbage.begin();

 while( w != garbage.end() )
 {
  delete (*w);
  w++;
 }

 w = editBoxes.begin();
 while( w != editBoxes.end() )
 {
  delete (*w);
  w++;
 }

 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);

 delete reg;	
}
//---------------------------------------------------------------------------






void __fastcall TFrmShowCustomers::editVatNoExit(TObject *Sender)
{
 if((dataset->State == dsEdit || dataset->State == dsInsert) && (editVatNo->Text.Length() < 9 || checkVatCode(editVatNo->Text) == false))
  showMessage("Το ΑΦΜ που πληκτρολογήσατε δεν είναι έγκυρο!",ApplicationName,MB_ICONERROR);
	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::DatasetCustomerAfterScroll(TDataSet *DataSet)
{
 showRecordsFetched();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::JvDotNetButton2Click(TObject *Sender)
{
 TFrmAddPayment *frmAddPayment = new TFrmAddPayment(Owner);

 frmAddPayment->setCustomerId(dataset->FieldByName("CUST_ID")->AsInteger);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolsPriorPaymentsClick(TObject *Sender)
{
 DatasetPayment->Prior();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolNextPaymentsClick(TObject *Sender)
{
 DatasetPayment->Next();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ToolRefreshPaymentsClick(TObject *Sender)
{
 DatasetPayment->Refresh();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::TollDeletePaymentsClick(TObject *Sender)
{
 if(DatasetPayment->RecordCount==0)
  return;

 try
 {
  DatasetPayment->Delete();
 }
 catch(Exception &e)
 {
  showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::FormShow(TObject *Sender)
{
 showRecordsFetched();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowCustomers::ToolAddClick(TObject *Sender)
{
 RollDetail->Collapsed = false;
 PageControl->ActivePageIndex = 0;
 DBEditName->SetFocus();
 dataset->Append();

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowCustomers::DatasetCustomerAfterInsert(TDataSet *DataSet)
{
// DatasetCustomer->FieldByName("CUST_ID")->AsInteger = 0;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::JvDotNetButton3Click(TObject *Sender)
{
 TFrmAddInvoice *frmInvoice = new TFrmAddInvoice(Owner,((TFrmMain *)Owner)->findInvType(dataset->FieldByName("CUST_ID")->AsInteger));

 frmInvoice->setCustomerId(dataset->FieldByName("CUST_ID")->AsInteger);
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::btnAddClick(TObject *Sender)
{
EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 10);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setField("NAME OCCUPATION AFM PHONE1 PHONE2 CITY", "Εύρεση:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::ViewCustInvoicesCellDblClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled)
{
//	TFrmEditInvoice *frmEditInvoice = new TFrmEditInvoice(Owner,QueryInvoice->FieldByName("INVOICE_ID")->AsInteger);
	TFrmShowInvoices *frmShowInvoices = new TFrmShowInvoices(Owner);
	frmShowInvoices->setInvoiceId(QueryInvoice->FieldByName("INVCODE")->AsString);
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowCustomers::JvDotNetButton1Click(TObject *Sender)
{
 dataset->Refresh();
 ShowMessage(dataset->FieldByName("PAYMETH_ID")->AsString);
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::DatasetCustomersAfterOpen(TDataSet *DataSet)
{
showRecordsFetched();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::radioMonthlyGroupClick(TObject *Sender)
{
 if(radioMonthlyGroup->Checked)
 {
  GridLevelCustInvoices->GridView = ViewMonthGroup;
 }
 else
  GridLevelCustInvoices->GridView = ViewCustInvoices;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::QueryMonthGroupBeforeOpen(TDataSet *DataSet)
{
// QueryMonthGroup->ParamByName("CUST_ID")->AsInteger = DatasetCustomers->FieldByName("CUST_ID")->AsInteger;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::PageControlChange(TObject *Sender)
{
 if(PageControl->ActivePageIndex == 1)
  QueryMonthGroup->Open();
 else
  QueryMonthGroup->Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::JvDotNetButton5Click(TObject *Sender)
{
  TFrmMailInvoices *FrmMail = new TFrmMailInvoices(Owner, QueryInvoice->FieldByName("INVOICE_ID")->AsInteger, false);
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowCustomers::cxDBTextEdit8PropertiesChange(TObject *Sender)
{
 if(editVatNo->Text.Length() < 9)
 {
  lblCheck->ImageIndex = 1;
  lblCheck->Hint = "Το ΑΦΜ δεν είναι έγκυρο!";
  return;
 }
 else
 {
  if(checkVatCode(editVatNo->Text) == true)
  {
   lblCheck->ImageIndex = 0;
   lblCheck->Hint = "Έγκυρο ΑΦΜ.";
  }
 }
}
//---------------------------------------------------------------------------


AnsiString TFrmShowCustomers::getInvoiceFilename(AnsiString _invTypeId)
{
 std::auto_ptr<TIBQuery> query =  AppController::getSmartNewQuery();

 query->SQL->Text = "SELECT FRM_FILENAME FROM INVTYPE WHERE INVTYPE_ID = :INVTYPE_ID";
 query->ParamByName("INVTYPE_ID")->AsString = _invTypeId;
 query->Open();

 return(query->FieldByName("FRM_FILENAME")->AsString);
}


void __fastcall TFrmShowCustomers::cxButton1Click(TObject *Sender)
{
TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			getInvoiceFilename(QueryInvoice->FieldByName("INVTYPE")->AsString),
			"INVOICE_ID",
			QueryInvoice->FieldByName("INVOICE_ID")->AsInteger, false);
}
//---------------------------------------------------------------------------

