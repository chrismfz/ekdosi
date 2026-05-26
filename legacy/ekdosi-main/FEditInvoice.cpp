//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FEditInvoice.h"

#include "RegistryAccess.h"
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
#pragma resource "*.dfm"
TFrmEditInvoice *FrmEditInvoice;


//---------------------------------------------------------------------------
__fastcall TFrmEditInvoice::TFrmEditInvoice(TComponent* Owner, unsigned int _invoiceId)
	: NewSpecialForm(Owner)
{
 //Set Cumulative invoice id to -1 to indicated that the reserve is handled by the main warehouse
 CumInvoiceId = -1;

 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();

 invoiceId = _invoiceId;

 QryInvTypes->Active = true;



 QryDistAim->Active = true;


 QryDeliveryMethod->Active = true;


 QryPaymentMeth->Active = true;


 QrySelProducts->Active = true;


 dataset = DatasetInvoice;
 dataset->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
 dataset->Active = true;


 DatasetInvLines->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
 DatasetInvLines->Active = true;

 //Check if reserve is handled by Cumulative invoice
 checkReserve();

 QrySelCustomer->Close();
 QrySelCustomer->ParamByName("CUST_ID")->AsInteger = dataset->FieldByName("CUST_ID")->AsInteger;

 QrySelCustomer->Open();

 Screen->OnActiveControlChange = ActiveControlChanged;

// GridInvoiceLines->Color = primary;
// GridInvoiceLines->AlternateRowColor = secondary;
// GridInvoiceLines->OnDrawColumnCell = this->GridDrawColumnCell;
 GridInvoiceLines->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridInvoiceLines->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridInvoiceLines->OnTitleBtnClick = this->GridTitleBtnClick;


 showData();
 showSums();
 
 dataset->Edit();

 lblEuroSign->Caption = FormatSettings.CurrencyString.Trim();
}
//---------------------------------------------------------------------------

void TFrmEditInvoice::setCustomerId(int _id)
{
 QrySelCustomer->Active = false;
 QrySelCustomer->ParamByName("CUSTID")->AsInteger = _id;
 QrySelCustomer->Active = true;
 DatasetInvoice->FieldByName("CUST_ID")->AsInteger = QrySelCustomer->FieldByName("CUST_ID")->AsInteger;
 showData();
}

void TFrmEditInvoice::setProductId(int _prId)
{
 QrySelProducts->Active = false;
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = _prId;
 QrySelProducts->Active = true;

 showLineData();

}

AnsiString TFrmEditInvoice::getInvCode()
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

void TFrmEditInvoice::showData()
{
 if(dataset->FieldByName("CUST_ID")->IsNull)
 {
  editName->Text = "";
  editVatNo->Text = "";
  editOccupation->Text = "";
  return;
 }
 
 editName->Text = QrySelCustomer->FieldByName("NAME")->AsString;
 editVatNo->Text = QrySelCustomer->FieldByName("AFM")->AsString;
 editOccupation->Text = QrySelCustomer->FieldByName("OCCUPATION")->AsString;

 editDiscPercent->Value = dataset->FieldByName("DISCOUNT")->AsInteger;
}

void TFrmEditInvoice::showLineData()
{
 DatasetInvLines->FieldByName("PRODUCT_ID")->AsString = QrySelProducts->FieldByName("PRODUCT_ID")->AsString;
 DatasetInvLines->FieldByName("DESCRIPTION_SHORT")->AsString = QrySelProducts->FieldByName("DESCRIPTION_SHORT")->AsString;
 DatasetInvLines->FieldByName("VATPERCENT")->AsInteger = QrySelProducts->FieldByName("VAT_VALUE")->AsInteger;
 DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency = QrySelProducts->FieldByName("SELL_PRICE")->AsCurrency;

 calcPrices();
}

void TFrmEditInvoice::calcPrices()
{

 DatasetInvLines->FieldByName("PRICE")->AsCurrency = (DatasetInvLines->FieldByName("QTY")->AsFloat * DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency);

 DatasetInvLines->FieldByName("PRICE")->AsCurrency = DatasetInvLines->FieldByName("PRICE")->AsCurrency -( DatasetInvLines->FieldByName("PRICE")->AsCurrency * (DatasetInvLines->FieldByName("DISCOUNT")->AsFloat/100));
 DatasetInvLines->FieldByName("PRICEWVAT")->AsCurrency =  DatasetInvLines->FieldByName("PRICE")->AsCurrency *((DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100)+1);

}

bool TFrmEditInvoice::checkAllFields()
{

 return(true);
}

bool TFrmEditInvoice::checkFieldsSdap()
{

 return(true);
}

void TFrmEditInvoice::showSums()
{
  TIBQuery *QrySum = new TIBQuery(this);
  
  QrySum->Database = database;
  QrySum->Transaction = transaction;


  QrySum->SQL->Add("SELECT SUM(PRICE) AS SM FROM INVLINES WHERE INVOICE_ID = :INVOICE_ID");
  QrySum->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
  QrySum->Active = true;
  if(dataset->State == dsEdit || dataset->State == dsInsert )
   dataset->FieldByName("PRICE")->AsCurrency = QrySum->FieldByName("SM")->AsCurrency - (QrySum->FieldByName("SM")->AsCurrency * (dataset->FieldByName("DISCOUNT")->AsFloat/100));
  lblPrice->Caption = CurrToStrF(dataset->FieldByName("PRICE")->AsCurrency,ffCurrency,2);

  QrySum->Active = false;
  QrySum->SQL->Clear();
  QrySum->SQL->Add("SELECT SUM(PRICEWVAT) AS SM FROM INVLINES WHERE INVOICE_ID = :INVOICE_ID");
  QrySum->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
  QrySum->Active = true;

  if(dataset->State == dsEdit || dataset->State == dsInsert )
   dataset->FieldByName("PRICEWVAT")->AsCurrency = QrySum->FieldByName("SM")->AsCurrency - (QrySum->FieldByName("SM")->AsCurrency * (dataset->FieldByName("DISCOUNT")->AsCurrency/100));
  lblTotal->Caption =  CurrToStrF(dataset->FieldByName("PRICEWVAT")->AsCurrency,ffCurrency,2);

  //show vat value
  lblVatValue->Caption = CurrToStrF(dataset->FieldByName("PRICEWVAT")->AsCurrency - dataset->FieldByName("PRICE")->AsCurrency,ffCurrency,2);
 delete QrySum; 
}

void TFrmEditInvoice::checkReserve()
{
 RegAccess *reg = new RegAccess(this);
 int reserve = reg->getAppParameterInt("Reserve");
 //if its 1 then reserve is taken from Cumulative Invoice
 if(reserve == 1)
 {
  //Check if Cumulative invoice exists
  CumInvoiceId = findCumInvoiceDate(dataset->FieldByName("INVDATE")->AsDateTime);
  if(CumInvoiceId == 0)
   showMessage("Δεν έχει εκδοθεί Συγκεντρωτικό Δελτίο Αποστόλής!",ApplicationName,MB_ICONEXCLAMATION);
 }
}

int TFrmEditInvoice::findCumInvoiceDate(TDate _date)
{
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Add("SELECT INVOICE_ID FROM INVOICE WHERE INVDATE = :INVDATE AND INVTYPE = 'ΣΔΑΠ';");
 query->ParamByName("INVDATE")->AsDate = _date;
 query->Active = true;

 if(query->FieldByName("INVOICE_ID")->IsNull )
 {
  delete query;
  return (0);
 }

 else
 {
  int tmp;
  tmp = query->FieldByName("INVOICE_ID")->AsInteger;
  delete query;
  return(tmp);
 }
}
void __fastcall TFrmEditInvoice::editNameKeyDown(TObject *Sender, WORD &Key,
      TShiftState Shift)
{
if( Key == 120)//F9
 {
  this->Enabled = false;
  TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId,editName->Text, editVatNo->Text);
 }		
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editVatNoKeyDown(TObject *Sender, WORD &Key,
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
void __fastcall TFrmEditInvoice::editVatNoKeyPress(TObject *Sender, char &Key)
{
// this->Caption = AnsiString((int)Key);
 if( (Key < '0' || Key > '9' )&& Key != 8)
  Key = 0;	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editNameChange(TObject *Sender)
{
 if(dataset->State != dsEdit)
  return;
  
 if(editName->Text.Trim() == QrySelCustomer->FieldByName("NAME")->AsString)
  showData();
 else
 {
  dataset->FieldByName("CUST_ID")->AsString = "";
  if(!editVatNo->Focused())
   editVatNo->Text = "";
  editOccupation->Text ="";
 }	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editVatNoChange(TObject *Sender)
{
 if(editVatNo->Text.Trim() == QrySelCustomer->FieldByName("AFM")->AsString)
  showData();
 else
 {
  editName->Text = "";
  editOccupation->Text ="";
 }		
}

//---------------------------------------------------------------------------

void __fastcall TFrmEditInvoice::ActiveControlChanged(TObject *Sender)
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

void __fastcall TFrmEditInvoice::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 Screen->OnActiveControlChange = 0;	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::GridInvoiceLinesKeyDown(TObject *Sender,
      WORD &Key, TShiftState Shift)
{
 return;
 if(Key == 45) //insert
 {
  TJvDBUltimGrid *grid = (TJvDBUltimGrid *)Sender;
  grid->Options = grid->Options >> dgRowSelect;
  grid->Options = grid->Options << dgEditing;
  DatasetInvLines->Append();
  
  Key = 0 ;
 }
 else if(Key == 46)//delete record -- delete key
 {
  if(DatasetInvLines->RecordCount > 0)
   DatasetInvLines->Delete();
 }
 else if(Key == 69 && DatasetInvLines->RecordCount > 0 && !( DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert ) ) // E key
 {
  TJvDBUltimGrid *grid = (TJvDBUltimGrid *)Sender;
  grid->Options = grid->Options >> dgRowSelect;
  grid->Options = grid->Options << dgEditing;  
  DatasetInvLines->Edit();
  someFlag = true;
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
void __fastcall TFrmEditInvoice::DatasetInvLinesAfterPost(TDataSet *DataSet)
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
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::GridInvoiceLinesDblClick(TObject *Sender)
{
return;
if(selRow<1 || selCol<1)
  return;

 if(DatasetInvLines->State != dsEdit || DatasetInvLines->State != dsInsert)
 {
  GridInvoiceLines->Options = GridInvoiceLines->Options >> dgRowSelect;
  GridInvoiceLines->Options = GridInvoiceLines->Options << dgEditing;
 }
 GridInvoiceLines->Col = selCol;;
 DatasetInvLines->Edit();		
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesAfterCancel(TDataSet *DataSet)
{
 GridInvoiceLines->Options = GridInvoiceLines->Options >> dgEditing;
 GridInvoiceLines->Options = GridInvoiceLines->Options << dgRowSelect;	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::GridInvoiceLinesMouseDown(TObject *Sender,
      TMouseButton Button, TShiftState Shift, int X, int Y)
{
 GridInvoiceLines->MouseToCell(X,Y, selCol, selRow);	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::GridInvoiceLinesKeyPress(TObject *Sender,
      char &Key)
{
if(someFlag == true)
 {
  Key = 0;
  someFlag = false;
 }	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesQTYChange(TField *Sender)
{
 calcPrices();	
}

//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesPRICE_PER_ITEMChange(
      TField *Sender)
{
 calcPrices();
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesDISCOUNTChange(TField *Sender)
{
calcPrices();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesBeforeEdit(TDataSet *DataSet)
{
QrySelProducts->Active = false;
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = DataSet->FieldByName("PRODUCT_ID")->AsInteger;
 QrySelProducts->Active = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editDiscPercentKeyPress(TObject *Sender,
      char &Key)
{
if( (Key < '0' || Key > '9') && Key != ',' && Key != 8)
  Key = 0;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editDiscPercentExit(TObject *Sender)
{
 if(editDiscPercent->Modified)
 {
  double discount;
  double DiscountEdit;
  try
  {
   DiscountEdit = editDiscPercent->Value;
   if(DiscountEdit > 100.0 || DiscountEdit < 0 )
   {
	showMessage("Παρακαλώ εισάγετε θετική τιμή μεγαλύτερη του 100",ApplicationName, MB_OK);
	editDiscPercent->SetFocus();
	editDiscPercent->SelectAll();
   }
  }
  catch(Exception &e)
  {
   ;
  }

  discount = dataset->FieldByName("PRICE")->AsCurrency * Currency((Currency(DiscountEdit)/100));
  dataset->FieldByName("DISCOUNT")->AsFloat = DiscountEdit;
  editDiscount->Value = CurrToStrF(discount,ffNumber,2);
 }

 showSums();
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::editDiscountExit(TObject *Sender)
{
 if(editDiscount->Modified)
 {
  double discountPercent;
  double DiscountEdit;
  try
  {
   DiscountEdit = editDiscount->Value;
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
  discountPercent = (DiscountEdit / dataset->FieldByName("PRICEWVAT")->AsCurrency)*100;
  dataset->FieldByName("DISCOUNT")->AsFloat = discountPercent;
  editDiscPercent->Value = CurrToStrF(discountPercent,ffNumber,2);

  showSums();
 }	
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::DatasetInvLinesAfterInsert(TDataSet *DataSet)
{
 DataSet->FieldByName("INVLINE_ID")->AsInteger = 0;
 DatasetInvLines->FieldByName("QTY")->AsInteger = 1;
 DatasetInvLines->FieldByName("DISCOUNT")->AsInteger = 0;
 DatasetInvLines->FieldByName("INVOICE_ID")->AsInteger = invoiceId;
}
//---------------------------------------------------------------------------
void __fastcall TFrmEditInvoice::JvDotNetButton1Click(TObject *Sender)
{
 // DatasetInvoice->FieldByName("INVCODE")->AsString = getInvCode(); NOT FOR EDIT MODE
// DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger = invoice_id ;
// DatasetInvoice->FieldByName("PRINTED")->AsInteger  = 0;

 if(dataset->FieldByName("INVTYPE")->AsString == "ΣΔΕΠ" ||
		dataset->FieldByName("INVTYPE")->AsString == "ΣΔΑΠ")
 {
  if(!checkFieldsSdap())
   return;
 }
 else
 {
  if(!checkAllFields())
   return;

   //Check if the invoice will be paid immediately
  double payment = 0.0;
  if(QryPaymentMeth->FieldByName("DUE_DAYS")->AsInteger == 0 )
  {
   payment = DatasetInvoice->FieldByName("PRICE")->AsFloat;
  }

  DatasetInvoice->Post();
 }

 transaction->Commit();


 if( showMessage("Εκτύπωση; ",ApplicationName, MB_YESNO) == 6)
 {//print
		TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			QryInvTypes->FieldByName("FRM_FILENAME")->AsString,
			"INVOICE_ID",
			dataset->FieldByName("INVOICE_ID")->AsInteger, true);
 }


 Close();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmEditInvoice::JvDotNetButton2Click(TObject *Sender)
{
 buttonRollback->Click();
 Close();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmEditInvoice::DatasetInvoiceBeforePost(TDataSet *DataSet)
{
 dataset->FieldByName("INVTIME")->AsDateTime = editTime->Time;		
}
//---------------------------------------------------------------------------

void __fastcall TFrmEditInvoice::DatasetInvoiceAfterScroll(TDataSet *DataSet)
{
 editTime->Time = dataset->FieldByName("INVTIME")->AsDateTime;	
}
//---------------------------------------------------------------------------

