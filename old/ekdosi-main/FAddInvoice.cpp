//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "Constants.h"
#include "RegistryAccess.h"
#include "FAddInvoice.h"
#include "FSelectCustomer.h"
#include "FSelectProduct.h"
#include "FShowNewBalance.h"
#include "FPrint.h"

//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxCalendar"
#pragma link "cxDBLookupComboBox"
#pragma link "cxDBLookupEdit"
#pragma link "cxDropDownEdit"
#pragma link "cxLookupEdit"
#pragma link "cxMaskEdit"
#pragma link "cxMemo"
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
TFrmAddInvoice *FrmAddInvoice;
//---------------------------------------------------------------------------
__fastcall TFrmAddInvoice::TFrmAddInvoice(TComponent* Owner, AnsiString invType)
	: NewSpecialForm(Owner)
{
 //Set Cumulative invoice id to -1 to indicated that the reserve is handled by the main warehouse
 CumInvoiceId = -1;
 notify = false;

 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();

 QryInvTypes->Active = true;
 QryDistAim->Active = true;
 QryDeliveryMethod->Active = true;
 QryPaymentMeth->Active = true;
 QryVatCategory->Active = true;
 QrySelProducts->Active = true;

 dataset = DatasetInvoice;
 dataset->Active = true;
  dataset->Insert();

 DatasetInvLines->Database = database;
 DatasetInvLines->Transaction = transaction;
 DatasetInvLines->Active = true;

 //Check if reserve is handled by Cumulative invoice
 checkReserve();

 
 Screen->OnActiveControlChange = ActiveControlChanged;

// GridInvoiceLines->Color = primary;
// GridInvoiceLines->AlternateRowColor = secondary;
// GridInvoiceLines->OnDrawColumnCell = this->GridDrawColumnCell;
 GridInvoiceLines->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridInvoiceLines->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridInvoiceLines->OnTitleBtnClick = this->GridTitleBtnClick;
 
 if(invType.Length() > 0)
 {
  dataset->FieldByName("INVTYPE")->AsString = invType;
  DatasetInvoice->FieldByName("DISTRAIM_ID")->AsInteger =  QryInvTypes->FieldByName("DISTAIM_ID")->AsInteger;
  DatasetInvoice->FieldByName("DELMETHOD_ID")->AsInteger =  QryInvTypes->FieldByName("DELIVERYMETHOD_ID")->AsInteger;
  DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger =  QryInvTypes->FieldByName("PAYMETH_ID")->AsInteger;
 }
 //show Euro sign
 lblEurosign->Caption = FormatSettings.CurrencyString;

 checkPricesWVat();
 this->ActionClose->OnExecute = ActionCloseExecNew;
}

void TFrmAddInvoice::setNotifier(ptrSetInvId _ptr)
{
 ptrSetInvoiceId = _ptr;

 notify = true;
}

void TFrmAddInvoice::setCustomerId(int _id)
{
 QrySelCustomer->Active = false;
 QrySelCustomer->ParamByName("CUSTID")->AsInteger = _id;
 QrySelCustomer->Active = true;
 DatasetInvoice->FieldByName("CUST_ID")->AsInteger = QrySelCustomer->FieldByName("CUST_ID")->AsInteger;
 showData();
}

void TFrmAddInvoice::setProductId(int _prId)
{
 QrySelProducts->Active = false;
 QrySelProducts->ParamByName("BARCODE")->Clear();
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = _prId;
 QrySelProducts->Active = true;

 showLineData();
 GridInvoiceLines->SetFocus();
}

AnsiString TFrmAddInvoice::getInvCode()
{
 TIBQuery *qry = new TIBQuery(this);
 qry->Database = database;
 qry->Transaction = transaction;
 qry->SQL->Add("SELECT INV_CNTCODE FROM GET_INV_CODE(:INVTYPE)");
 qry->ParamByName("INVTYPE")->AsString = DatasetInvoice->FieldByName("INVTYPE")->AsString;
 qry->Open();
 AnsiString invCode = qry->FieldByName("INV_CNTCODE")->AsString;
 delete qry;

 return(invCode);
}

void TFrmAddInvoice::showData()
{
 editName->Text = QrySelCustomer->FieldByName("NAME")->AsString;
 editVatNo->Text = QrySelCustomer->FieldByName("AFM")->AsString;
 editOccupation->Text = QrySelCustomer->FieldByName("OCCUPATION")->AsString;
// editAddress1->Text = QrySelCustomer->FieldByName("ADDRESS1")->AsString;
// editAddress2->Text = QrySelCustomer->FieldByName("ADDRESS2")->AsString;
 DatasetInvoice->FieldByName("ADDRESS1")->AsString = QrySelCustomer->FieldByName("ADDRESS1")->AsString;
 DatasetInvoice->FieldByName("ADDRESS2")->AsString = QrySelCustomer->FieldByName("ADDRESS2")->AsString;
 DatasetInvoice->FieldByName("CITY")->AsString = QrySelCustomer->FieldByName("CITY")->AsString;
 DatasetInvoice->FieldByName("POSTCODE")->AsString = QrySelCustomer->FieldByName("POSTCODE")->AsString;
 }

void TFrmAddInvoice::showLineData()
{
 DatasetInvLines->FieldByName("PRODUCT_ID")->AsString = QrySelProducts->FieldByName("PRODUCT_ID")->AsString;
 DatasetInvLines->FieldByName("BARCODE")->AsString = QrySelProducts->FieldByName("BARCODE")->AsString;
 DatasetInvLines->FieldByName("DESCRIPTION_SHORT")->AsString = QrySelProducts->FieldByName("DESCRIPTION_SHORT")->AsString;
 DatasetInvLines->FieldByName("VATPERCENT")->AsFloat = QrySelProducts->FieldByName("VAT_VALUE")->AsFloat;
 DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency = QrySelProducts->FieldByName("SELL_PRICE")->AsCurrency;

 calcPrices();
}

void TFrmAddInvoice::calcPrices()
{
 DatasetInvLines->FieldByName("PRICE")->AsCurrency = (DatasetInvLines->FieldByName("QTY")->AsFloat * DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency);

 double discountValue = DatasetInvLines->FieldByName("PRICE")->AsCurrency * (DatasetInvLines->FieldByName("DISCOUNT")->AsFloat/100);
 DatasetInvLines->FieldByName("PRICE")->AsCurrency = DatasetInvLines->FieldByName("PRICE")->AsCurrency - discountValue;
 DatasetInvLines->FieldByName("PRICEWVAT")->AsCurrency =  DatasetInvLines->FieldByName("PRICE")->AsCurrency *((DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100)+1);
 if(GridInvoiceLines->SelectedField->FieldName != "PRICE_PER_ITEM_WVAT")
  DatasetInvLines->FieldByName("PRICE_PER_ITEM_WVAT")->AsCurrency = DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency * ((DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100)+1);
}

bool TFrmAddInvoice::checkAllFields()
{
 if(DatasetInvoice->FieldByName("INVTYPE")->IsNull)
 {
  comboInvType->SetFocus();
  showMessage("Παρακαλώ επιλέξτε τύπο παραστατικού",ApplicationName,MB_ICONERROR);
  return(false);
 }
 else if(DatasetInvoice->FieldByName("CUST_ID")->IsNull || DatasetInvoice->FieldByName("CUST_ID")->AsInteger == 0 || editName->Text.Length() == 0)
 {
  editName->SetFocus();
  showMessage("Παρακαλώ επιλέξτε πελάτη",ApplicationName,MB_ICONERROR);
  return(false);
 }
 else if(DatasetInvoice->FieldByName("DISTRAIM_ID")->IsNull)
 {
  comboDistAim->SetFocus();
  showMessage("Παρακαλώ επιλέξτε σκοπό διακίνησης",ApplicationName,MB_ICONERROR);
  return(false);
 }
 else if(DatasetInvoice->FieldByName("DELMETHOD_ID")->IsNull)
 {
  comboDeliveryMethod->SetFocus();
  showMessage("Παρακαλώ επιλέξτε τρόπο αποστολής",ApplicationName,MB_ICONERROR);
  return(false);
 }
 else if(DatasetInvoice->FieldByName("PAYMETH_ID")->IsNull)
 {
  comboPaymeth->SetFocus();
  showMessage("Παρακαλώ επιλέξτε τρόπο πληρωμής",ApplicationName,MB_ICONERROR);
  return(false);
 }

 //Are there any invoice lines?
 DatasetInvLines->Last();
 if(DatasetInvLines->RecordCount == 0)
 {
  GridInvoiceLines->SetFocus();
  showMessage("Παρακαλώ εισάγετε προιόντα",ApplicationName,MB_ICONERROR);
  return(false);
 }

 return(true);
}

void TFrmAddInvoice::genInvoiceId()
{
 TIBQuery *tmpQuery = new TIBQuery(this);

 tmpQuery->SQL->Add("SELECT GEN_ID(GEN_INVOICE_ID,1) FROM RDB$DATABASE;");
 tmpQuery->Database = database;
 tmpQuery->Transaction = transaction;
 tmpQuery->Active = true;

 invoice_id = tmpQuery->FieldByName("GEN_ID")->AsInteger;

 delete tmpQuery;
}

void TFrmAddInvoice::showSums()
{
  double priceSumWVat;
  priceSumWVat = getPriceSumWVat();
  
  if(dataset->State == dsEdit || dataset->State == dsInsert )
  {
   double priceWOutVat = getPriceSumWOutVat();
   dataset->FieldByName("PRICE")->AsCurrency = priceWOutVat - (priceWOutVat * (dataset->FieldByName("DISCOUNT")->AsFloat/100));
  }
  lblPrice->Caption = CurrToStrF(dataset->FieldByName("PRICE")->AsCurrency,ffCurrency,2);


  if(dataset->State == dsEdit || dataset->State == dsInsert )
   dataset->FieldByName("PRICEWVAT")->AsCurrency = priceSumWVat - (priceSumWVat * (dataset->FieldByName("DISCOUNT")->AsFloat/100));
  
  lblTotal->Caption =  CurrToStrF(dataset->FieldByName("PRICEWVAT")->AsCurrency,ffCurrency,2);

  //show vat value
  lblVatValue->Caption = CurrToStrF(dataset->FieldByName("PRICEWVAT")->AsCurrency - dataset->FieldByName("PRICE")->AsCurrency,ffCurrency,2);

  //update discount VALUE (in money)
  if(!editDiscount->Focused() && dataset->FieldByName("DISCOUNT")->AsFloat > 0)
  {
   double discount = getPriceSumWVat() * (dataset->FieldByName("DISCOUNT")->AsFloat/100);
   editDiscount->Text = CurrToStrF(discount,ffNumber,2);
  }
}

void TFrmAddInvoice::checkReserve()
{
 RegAccess *reg = new RegAccess(this);
 int reserve = reg->getAppParameterInt("Reserve");
 //if its 1 then reserve is taken from Cumulative Invoice
 if(reserve == 1)
 {
  //Check if Cumulative invoice exists
  CumInvoiceId = findCumInvoiceDate(runningDate);
  if(CumInvoiceId == 0)
  {
   lblSdap->Visible = false;
   showMessage("Δεν έχει εκδοθεί Συγκεντρωτικό Δελτίο Αποστόλής!",ApplicationName,MB_ICONEXCLAMATION);
  }
  else
  {
   lblSdap->Visible = true;
   TIBQuery *query = new TIBQuery(this);
   query->Database = database;
   query->Transaction = transaction;
   query->SQL->Text = "SELECT INVCODE FROM INVOICE WHERE INVOICE_ID = :INVOICE_ID";
   query->ParamByName("INVOICE_ID")->AsInteger = CumInvoiceId;
   query->Open();
   lblSdap->Caption = "Συγκ. ΔΑΠ: "+query->FieldByName("INVCODE")->AsString;
   delete query;
  }
 }
 else  if(reserve == 0)
 {
  CumInvoiceId = 0;
  lblSdap->Visible = false;
 }
 else if(reserve == 2)
 {
  CumInvoiceId = -1;
  lblSdap->Visible = false;
 }

 delete reg;
}

void TFrmAddInvoice::showButtons()
{
 cmdNewItem->Show();
 cmdDeleteItem->Show();
 cmdEditItem->Show();

 PanelDetails->Enabled = true;
 PanelButtons->Enabled = true;
}

void TFrmAddInvoice::hideButtons()
{
 cmdNewItem->Hide();
 cmdDeleteItem->Hide();
 cmdEditItem->Hide();

 PanelDetails->Enabled = false;
 PanelButtons->Enabled = false;
}

void TFrmAddInvoice::checkPricesWVat()
{
 RegAccess *reg = new RegAccess(this);
 if(reg->getAppParameterInt("GridPricesWVat") == 1)
  gridPricesWVat = true;
 else
  gridPricesWVat = false;

 delete reg;
}

int TFrmAddInvoice::findCumInvoiceDate(TDate _date)
{
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Add("SELECT INVOICE_ID,INVTYPE,CONV_INVOICE_ID FROM INVOICE WHERE INVDATE = :INVDATE  ORDER BY INVOICE_ID DESC");
 query->ParamByName("INVDATE")->AsDate = _date;
 query->Active = true;

  query->Last();
 int recordNumber = query->RecordCount;
 query->First();

 while( (query->FieldByName("INVTYPE")->AsString != "ΣΔΑΠ" || !query->FieldByName("CONV_INVOICE_ID")->IsNull ) && query->RecNo < recordNumber)
 {
  if(query->FieldByName("INVTYPE")->AsString == "ΣΔΕΠ" )
  {
   delete query;
	return(0);
  }
  query->Next();
 }

  int tmp;
  tmp = query->FieldByName("INVOICE_ID")->AsInteger;
  delete query;
  return(tmp);
}

double TFrmAddInvoice::getPriceSumWVat()
{
 TIBQuery *QrySum = new TIBQuery(this);
 double tmp;

 QrySum->Database = database;
 QrySum->Transaction = transaction;
 QrySum->SQL->Add("SELECT SUM(PRICEWVAT) AS SM FROM INVLINES WHERE INVOICE_ID IS NULL");
 QrySum->Active = true;
 tmp =QrySum->FieldByName("SM")->AsCurrency;
 delete QrySum;

 return(tmp);
}

double TFrmAddInvoice::getPriceSumWOutVat()
{
 TIBQuery *QrySum = new TIBQuery(this);
 double tmp;

  QrySum->Database = database;
  QrySum->Transaction = transaction;


  QrySum->SQL->Add("SELECT SUM(PRICE) AS SM FROM INVLINES WHERE INVOICE_ID IS NULL");
  QrySum->Active = true;
 tmp =QrySum->FieldByName("SM")->AsCurrency;
 delete QrySum;

 return(tmp);
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::FormShow(TObject *Sender)
{

 //Generate unique invoice id
 genInvoiceId();

 dataset->FieldByName("INVOICE_ID")->AsInteger = invoice_id;
 dataset->FieldByName("INVDATE")->AsDateTime = runningDate;
 dataset->FieldByName("DELIVERYDATE")->AsDateTime = runningDate;
 dataset->FieldByName("DISCOUNT")->AsCurrency = 0.0;

// DatasetInvLines->Insert();
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::editNameKeyDown(TObject *Sender,
	  WORD &Key, TShiftState Shift)
{
 if( Key == 120)//F9
 {
  this->Enabled = false;
  TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId,editName->Text, editVatNo->Text);
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editVatNo1KeyDown(TObject *Sender, WORD &Key,
      TShiftState Shift)
{
 //check where F9 is pressed and if VatNo enter exist in the database. if exist fetch it
 if( Key == 120)//F9
 {
  TIBQuery *qry = new TIBQuery(this);

  qry->SQL->Add("SELECT CUST_ID FROM CUSTOMER WHERE AFM = :AFM");
  qry->Database = database;
  qry->Transaction = transaction;
  qry->ParamByName("AFM")->AsString = editVatNo->Text;
  
  qry->Active = true;
  qry->Last();
  if(qry->RecordCount == 1)
  {
   setCustomerId(qry->FieldByName("CUST_ID")->AsInteger);
   qry->Active = false;
  }
  else
  {
   qry->Active = false;
   this->Enabled = false;
   TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId,editName->Text, editVatNo->Text);
  }
   delete qry;
 }


}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editVatNo1KeyPress(TObject *Sender, char &Key)
{
// this->Caption = AnsiString((int)Key);
 if( (Key < '0' || Key > '9' )&& Key != 8)
  Key = 0;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editNameChange(TObject *Sender)
{
 if(editName->Text.Trim() == QrySelCustomer->FieldByName("NAME")->AsString.Trim())
  showData();
 else
 {
  dataset->FieldByName("CUST_ID")->AsInteger = 0;
  if(!editVatNo->Focused())
   editVatNo->Text = "";
  editOccupation->Text ="";
  DatasetInvoice->FieldByName("ADDRESS1")->AsString = "";
  DatasetInvoice->FieldByName("ADDRESS2")->AsString = "";
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editVatNo1Change(TObject *Sender)
{
 if(editVatNo->Text.Trim() == QrySelCustomer->FieldByName("AFM")->AsString)
  showData();
 else
 {
  editName->Text = "";
  editOccupation->Text ="";
//  editAddress1->Text = "";
   DatasetInvoice->FieldByName("ADDRESS1")->AsString = "";

//  editAddress2->Text = "";
  DatasetInvoice->FieldByName("ADDRESS2")->AsString = "";
 }
}
//---------------------------------------------------------------------------




void __fastcall TFrmAddInvoice::ActiveControlChanged(TObject *Sender)
{
 //showMessage(AnsiString(Sender->ClassName()).c_str(),"EKDOSI",MB_OK);
 if( ( AnsiString(Screen->ActiveControl->ClassName()) == AnsiString("TJvDotNetEdit")
	   || AnsiString(Screen->ActiveControl->ClassName()) == AnsiString("TJvDBLookupCombo")
	   || AnsiString(Screen->ActiveControl->ClassName()) == AnsiString("TJvDBDateEdit"))
	 && Screen->ActiveForm == this)
  StatusBar->Panels->Items[0]->Text = ((TJvDotNetEdit *)Screen->ActiveControl)->Hint;
 else
  StatusBar->Panels->Items[0]->Text = "";

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 Screen->OnActiveControlChange = 0;

 //transaction->Rollback();

}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::GridInvoiceLinesKeyDown(TObject *Sender,
      WORD &Key, TShiftState Shift)
{
 if(Key == 45) //insert
 {
  MenuAddLine->Click();  
  Key = 0 ;
 }
 else if(Key == 46)//delete record -- delete key
 {
  MenuDeleteLine->Click();
 }
 else if(Key == 69 && DatasetInvLines->RecordCount > 0 && !( DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert ) ) // E key
 {
  MenuEditLine->Click();
 }
 else if( Key == 120 && ( DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert ))//F9
 {
  AnsiString tmpDescription, tmpCode;
  if(GridInvoiceLines->EditorMode && GridInvoiceLines->Col == 2)
   tmpDescription = GridInvoiceLines->InplaceEditor->Text;
  else
   tmpDescription = GridInvoiceLines->Fields[1]->AsString;

  if(GridInvoiceLines->EditorMode && GridInvoiceLines->Col == 1)
   tmpCode = GridInvoiceLines->InplaceEditor->Text;
  else
   tmpCode = GridInvoiceLines->Fields[0]->AsString;

  this->Enabled = false;
  TFrmSelectProduct *frmSelectCProductr = new TFrmSelectProduct(Owner, this, &setProductId , tmpDescription,tmpCode );
 }

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesAfterPost(TDataSet *DataSet)
{
 GridInvoiceLines->Options = GridInvoiceLines->Options >> dgEditing;
 GridInvoiceLines->Options = GridInvoiceLines->Options << dgRowSelect;
 if(DataSet->FieldByName("PRODUCT_ID")->IsNull)
 {
  DataSet->Delete();
 }

 TByteDynArray bookmark;
 bookmark = DataSet->GetBookmark();
 try
 {
  DataSet->Active = false;
  DataSet->Active = true;
 }catch(Exception &e)
 {
  ;
 }

 DataSet->GotoBookmark(bookmark);
 showSums();

 QrySelProducts->Active = false;
 cmdAccept->Hide();
 cmdSearch->Hide();
 cmdCancel->Hide();
 showButtons();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::GridInvoiceLinesDblClick(TObject *Sender)
{
 if(selRow<1 || selCol<1)
 {
  return;
 }


 if(DatasetInvLines->State != dsEdit || DatasetInvLines->State != dsInsert)
 {
  GridInvoiceLines->Options = GridInvoiceLines->Options >> dgRowSelect;
  GridInvoiceLines->Options = GridInvoiceLines->Options << dgEditing;
 }
 GridInvoiceLines->Col = selCol;
 DatasetInvLines->Edit();
}
//---------------------------------------------------------------------------




void __fastcall TFrmAddInvoice::DatasetInvLinesAfterCancel(TDataSet *DataSet)
{
 GridInvoiceLines->Options = GridInvoiceLines->Options >> dgEditing;
 GridInvoiceLines->Options = GridInvoiceLines->Options << dgRowSelect;
 QrySelProducts->Active = false;
 cmdAccept->Visible = false;
 cmdCancel->Hide();
 cmdSearch->Hide();
 showButtons();
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::GridInvoiceLinesMouseDown(TObject *Sender,
      TMouseButton Button, TShiftState Shift, int X, int Y)
{
 GridInvoiceLines->MouseToCell(X,Y, selCol, selRow);
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::GridInvoiceLinesKeyPress(TObject *Sender,
      char &Key)
{
 if(someFlag == true)
 {
  Key = 0;
  someFlag = false;
 }
}
//---------------------------------------------------------------------------

double TFrmAddInvoice::returnAvailQty(int SdapInvId, int productId)
{                                   
 double qty;
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;

 if( (DatasetInvLines->State == dsInsert || DatasetInvLines->State == dsEdit)  && CumInvoiceId > 0 )    //if it's using comulative Invoice
 {
  query->SQL->Text = "SELECT QTY FROM CHECK_PROD_AVAILABILITY(:PRODUCT_ID, :SDAP_INV_ID);";
  query->ParamByName("PRODUCT_ID")->AsInteger = productId;
  query->ParamByName("SDAP_INV_ID")->AsInteger = SdapInvId;
 }
 else      //if it's using warehouse stock
 {
  query->SQL->Text = "SELECT COALESCE(RESERVE,0.0) AS QTY FROM PRODUCT WHERE PRODUCT_ID = :PRODUCT_ID;";
  query->ParamByName("PRODUCT_ID")->AsInteger = productId;
 }

 query->Open();
 qty = query->FieldByName("QTY")->AsFloat;
 delete query;
 return(qty);
}



void __fastcall TFrmAddInvoice::DatasetInvLinesQTYChange(TField *Sender)
{
 if(DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert)
 {
  double qty = returnAvailQty(CumInvoiceId, DatasetInvLines->FieldByName("PRODUCT_ID")->AsInteger);

  if(Sender->AsFloat > qty && CumInvoiceId != -1) // if CumInvoiceId ==-1 DO NOT CHECK availability
  {
   Sender->AsFloat = qty;
   showMessage("Δεν υπάρχει διαθεσιμότητα",ApplicationName, MB_ICONERROR);
  }
 }
 calcPrices();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesPRICE_PER_ITEMChange(
      TField *Sender)
{
 calcPrices();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::GridInvoiceLinesColEnter(TObject *Sender)
{
 if(GridInvoiceLines->Col > 2)
 {
  if(DatasetInvLines->FieldByName("BARCODE")->AsString.Length() >0 && (QrySelProducts->Active == false || QrySelProducts->FieldByName("PRODUCT_ID")->IsNull))
  {
   showMessage("Ο κωδικός δεν βρέθηκε!",ApplicationName,MB_ICONERROR);
   GridInvoiceLines->Col = 2;
  }
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::GridInvoiceLinesColExit(TObject *Sender)
{

 if(GridInvoiceLines->Col == 1 && QrySelProducts->FieldByName("BARCODE")->AsString != DatasetInvLines->FieldByName("BARCODE")->AsString)//check code on exit
 {
  QrySelProducts->Active = false;
  QrySelProducts->ParamByName("BARCODE")->AsString = DatasetInvLines->FieldByName("BARCODE")->AsString;
  QrySelProducts->ParamByName("PRODUCTID")->Clear();
  QrySelProducts->Active = true;

  if(QrySelProducts->FieldByName("BARCODE")->AsString == DatasetInvLines->FieldByName("BARCODE")->AsString)
  {
   showLineData();
  }
  else
  {
   DatasetInvLines->FieldByName("PRODUCT_ID")->AsString = "";
   DatasetInvLines->FieldByName("DESCRIPTION_SHORT")->AsString = "";
   DatasetInvLines->FieldByName("VATPERCENT")->AsFloat = 0;
   DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency = 0;
  }
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesDISCOUNTChange(TField *Sender)
{
 calcPrices();
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::DatasetInvLinesBeforeEdit(TDataSet *DataSet)
{
 QrySelProducts->Active = false;
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = DataSet->FieldByName("PRODUCT_ID")->AsInteger;
 QrySelProducts->Active = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editDiscPercentKeyPress(TObject *Sender,
      char &Key)
{
 if( (Key < '0' || Key > '9') && Key != ',' && Key != 8)
   Key = 0;
 else
 {
  if(editDiscPercent->Text.Length() >0 && Key !=8 )
   if(AnsiString(editDiscPercent->Text+Key).ToDouble() > 100)
    Key = 0;
 }
}
//---------------------------------------------------------------------------





void __fastcall TFrmAddInvoice::DatasetInvLinesAfterInsert(TDataSet *DataSet)
{
 DataSet->FieldByName("INVLINE_ID")->AsInteger = 0;
 DatasetInvLines->FieldByName("DISCOUNT")->AsInteger = 0;
 cmdAccept->Visible = true;
 cmdCancel->Show();
 hideButtons();
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddInvoice::JvDotNetButton1Click(TObject *Sender)
{
	DatasetInvoice->FieldByName("INVCODE")->AsString = getInvCode();
 DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger = invoice_id ;
	DatasetInvoice->FieldByName("PRINTED")->AsInteger  = 0;
	if(checkWithhold->Checked)
 	DatasetInvoice->FieldByName("WITHHOLD_AMOUNT")->AsFloat = DatasetInvoice->FieldByName("PRICE")->AsFloat * 0.2;

 if(!checkAllFields())
  return;

 AnsiString filename = QryInvTypes->FieldByName("FRM_FILENAME")->AsString;

  //Check if the invoice will be paid immediately
 double payment = 0.0;
 if(QryPaymentMeth->FieldByName("DUE_DAYS")->AsInteger == 0 )
 {
  payment = DatasetInvoice->FieldByName("PRICEWVAT")->AsFloat;
 }

 DatasetInvoice->Post();

 DatasetInvLines->Last();
 int recordCount =  DatasetInvLines->RecordCount;
 DatasetInvLines->First();

 for(int i=0;i< recordCount;i++)
 {
  //update reverse here
  DatasetInvLines->Edit();

  DatasetInvLines->FieldByName("INVOICE_ID")->AsString = invoice_id;
  DatasetInvLines->Post();

  DatasetInvLines->Next();
 }

 transaction->CommitRetaining();

 if( showMessage("Εκτύπωση; ",ApplicationName, MB_YESNO) == 6)
 {//print
  TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			filename,
			"INVOICE_ID",
			invoice_id,true);
 }


 if(notify)
  (ptrSetInvoiceId)(invoice_id);

  //Do not show new balance dialog -- not needed
 //TFrmShowNewBalance *frmShowNewBalance = new TFrmShowNewBalance(Owner,invoice_id, payment);

 Close();
}
//---------------------------------------------------------------------------

//update reserve method
void TFrmAddInvoice::updateReserve(int productId, double qty)
{
 TIBQuery *qry = new TIBQuery(this);
 qry->Database = database;
 qry->Transaction = transaction;
 
 qry->SQL->Add("UPDATE PRODUCT SET QTY = QTY - :NEW_QTY WHERE PRODUCT_ID = :PRODUCT_ID");
 qry->ParamByName("NEW_QTY")->AsFloat = qty;
 qry->ParamByName("PRODUCT_ID")->AsInteger = productId;
 qry->ExecSQL();

 delete qry;
}

void __fastcall TFrmAddInvoice::FormActivate(TObject *Sender)
{
 transaction->DefaultAction =  TARollback; //Rollback remaining invoice lines
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesBeforePost(TDataSet *DataSet)
{
 if(DataSet->FieldByName("PRODUCT_ID")->IsNull || DataSet->FieldByName("PRICE_PER_ITEM")->IsNull )
  Abort();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::JvDotNetButton2Click(TObject *Sender)
{
 buttonRollback->Click();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvoiceBeforePost(TDataSet *DataSet)
{
 dataset->FieldByName("INVTIME")->AsDateTime = editTime->Time;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::DatasetInvLinesAfterDelete(TDataSet *DataSet)
{
 showSums();
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddInvoice::DatasetInvLinesPRODUCT_IDChange(TField *Sender)
{
 if(DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert )
 {
  DatasetInvLines->FieldByName("QTY")->AsInteger = 1;
 }	
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::ActionCloseExecNew(TObject *Sender)
{
 if(DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert)
 {
  DatasetInvLines->Cancel();
    return;
 }


 int answer = showMessage("Κλείσιμο;",ApplicationName, MB_YESNO);
 if( answer == 6) //yes
 {
  this->Close();
 }
 
}
void __fastcall TFrmAddInvoice::MenuAddLineClick(TObject *Sender)
{
 GridInvoiceLines->Options = GridInvoiceLines->Options >> dgRowSelect;
 GridInvoiceLines->Options = GridInvoiceLines->Options << dgEditing;
 DatasetInvLines->Append();
 cmdSearch->Show();
 
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::MenuDeleteLineClick(TObject *Sender)
{
 if(DatasetInvLines->RecordCount > 0)
   DatasetInvLines->Delete();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::MenuEditLineClick(TObject *Sender)
{
 TJvDBUltimGrid *grid = GridInvoiceLines;
 grid->Options = grid->Options >> dgRowSelect;
 grid->Options = grid->Options << dgEditing;
 DatasetInvLines->Edit();
 someFlag = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdNewItemClick(TObject *Sender)
{
 MenuAddLine->Click();	

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdDeleteItemClick(TObject *Sender)
{
 MenuDeleteLine->Click();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdEditItemClick(TObject *Sender)
{
 MenuEditLine->Click();
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::QryVatCategoryAfterOpen(TDataSet *DataSet)
{
 QryVatCategory->First();
 int i;
 for(i=0;i<GridInvoiceLines->Columns->Count;i++)
  if(GridInvoiceLines->Columns->Items[i]->FieldName == "VATPERCENT")
   break;

 GridInvoiceLines->Columns->Items[i]->PickList->Clear();
 while(!QryVatCategory->Eof)
 {
  GridInvoiceLines->Columns->Items[i]->PickList->Add(QryVatCategory->FieldByName("VALUE")->AsFloat);
  QryVatCategory->Next();
 }
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddInvoice::GridInvoiceLinesCellClick(TColumn *Column)
{
 if( (DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert) && (selCol == 1 || selCol == 2))
  cmdSearch->Show();
 else
  cmdSearch->Hide();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesAfterEdit(TDataSet *DataSet)
{
 hideButtons();
 cmdAccept->Show();
 cmdCancel->Show();
 if( (DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert) && (selCol == 1 || selCol == 2))
  cmdSearch->Show();
 else
  cmdSearch->Hide();

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdSearchClick(TObject *Sender)
{
 unsigned short Key = 120;
 TShiftState a;
 GridInvoiceLinesKeyDown(Sender,Key,a);
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdAcceptClick(TObject *Sender)
{
 DatasetInvLines->Post();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdCancelClick(TObject *Sender)
{
 DatasetInvLines->Cancel();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::DatasetInvLinesPRICE_PER_ITEM_WVATValidate(
      TField *Sender)
{
 if(GridInvoiceLines->SelectedField->FieldName == "PRICE_PER_ITEM_WVAT")
  DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency =
		DatasetInvLines->FieldByName("PRICE_PER_ITEM_WVAT")->AsCurrency / (1+(DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100));
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddInvoice::GridInvoiceLinesEditChange(TObject *Sender)
{
 //if VATPERCENT is changed
 if(DatasetInvLines->State == dsInsert || DatasetInvLines->State == dsEdit)
 {
  int i;
 for(i=0;i<GridInvoiceLines->Columns->Count;i++)
  if(GridInvoiceLines->Columns->Items[i]->FieldName == "VATPERCENT")
   break;
  DatasetInvLines->FieldByName("VATPERCENT")->AsFloat = GridInvoiceLines->Columns->Items[i]->Field->AsFloat;
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::DatasetInvLinesVATPERCENTValidate(
      TField *Sender)
{
 calcPrices();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::cmdCalculeteDiscountClick(TObject *Sender)
{
 if(!editDiscount->Focused() && !editDiscPercent->Focused() )
  showSums();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editDiscPercentChange(TObject *Sender)
{
 if(editDiscPercent->Text.Length() >0 && editDiscPercent->Focused())
 {
  double discount;
  double DiscountEdit;
  try
  {
   DiscountEdit = editDiscPercent->Text.ToDouble();   
  }
  catch(Exception &e)
  {
   ;
  }

  discount = getPriceSumWVat() * (DiscountEdit/100);
  dataset->FieldByName("DISCOUNT")->AsFloat = DiscountEdit;
  editDiscount->Text = CurrToStrF(discount,ffNumber,2);

  showSums();
 }
 else if(editDiscPercent->Text.Length() == 0 && editDiscPercent->Focused())
 {
   dataset->FieldByName("DISCOUNT")->AsFloat = 0;
   editDiscount->Text = CurrToStrF(0,ffNumber,2);
   showSums();
 }
 
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editDiscountChange(TObject *Sender)
{
 if(editDiscount->Text.Length() >0 && editDiscount->Focused() )
 {
  double discountPercent;
  double DiscountEdit;
  try
  {
   DiscountEdit = editDiscount->Text.ToDouble();
   if(DiscountEdit < 0 )
   {
	showMessage("Παρακαλώ εισάγετε θετική τιμή",ApplicationName, MB_OK);
	editDiscount->SetFocus();
	editDiscount->SelectAll();
   }
  }
  catch(Exception &e)
  {
   ;
  }

  if(getPriceSumWVat() > 0)
   discountPercent = (DiscountEdit / getPriceSumWVat() )*100;
  else 
   discountPercent = 0;
  dataset->FieldByName("DISCOUNT")->AsFloat = discountPercent;
  editDiscPercent->Text = CurrToStrF(discountPercent,ffNumber,2);
  
  showSums();
 }	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::editDiscountKeyPress(TObject *Sender, char &Key)
{
 if( (Key < '0' || Key > '9') && Key != ',' && Key != 8)
   Key = 0;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::JvDBLookupCombo2Change(TObject *Sender)
{
 if(DatasetInvoice->State == dsInsert || DatasetInvoice->State == dsEdit)
 {
  DatasetInvoice->FieldByName("DISTRAIM_ID")->AsInteger =  QryInvTypes->FieldByName("DISTAIM_ID")->AsInteger;
  DatasetInvoice->FieldByName("DELMETHOD_ID")->AsInteger =  QryInvTypes->FieldByName("DELIVERYMETHOD_ID")->AsInteger;

  if(QrySelCustomer->FieldByName("PAYMETH_ID")->IsNull == true )
	DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger =  QryInvTypes->FieldByName("PAYMETH_ID")->AsInteger;
  else
	DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger =  QrySelCustomer->FieldByName("PAYMETH_ID")->AsInteger;
 }
}
//---------------------------------------------------------------------------

void TFrmAddInvoice::addInvLine(int _productId, AnsiString _description, double _qty, double _price, int _taxed)
{
 MenuAddLineClick(NULL);

 _description.Delete(250,_description.Length());

 setProductId(_productId);

 if(_taxed == 0)
 {
  GridInvoiceLines->SelectedField =  DatasetInvLines->FieldByName("PRICE_PER_ITEM_WVAT");
  DatasetInvLines->FieldByName("PRICE_PER_ITEM_WVAT")->AsCurrency = _price;
 }
 else
 {
  GridInvoiceLines->SelectedField =  DatasetInvLines->FieldByName("PRICE_PER_ITEM");
  DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency = _price;
 }

 DatasetInvLines->FieldByName("PRODUCT_DESCR")->AsString = _description;
 DatasetInvLines->FieldByName("QTY")->AsString = 1;

 calcPrices();
 DatasetInvLines->Post();
}

void __fastcall TFrmAddInvoice::comboDistAimEnter(TObject *Sender)
{
comboDistAim->DroppedDown = true;
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice::comboDeliveryMethodEnter(TObject *Sender)
{
 comboDeliveryMethod->DroppedDown = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::comboPaymethEnter(TObject *Sender)
{
 comboPaymeth->DroppedDown = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice::QrySelCustomerAfterOpen(TDataSet *DataSet)
{
 if(DatasetInvoice->FieldByName("PAYMETH_ID")->IsNull)
		DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger = QrySelCustomer->FieldByName("PAYMETH_ID")->AsInteger;

	if(QrySelCustomer->FieldByName("WITHHOLD_TAX")->AsInteger==1)
		checkWithhold->Checked = true;
	else
		checkWithhold->Checked = false;
}
//---------------------------------------------------------------------------



