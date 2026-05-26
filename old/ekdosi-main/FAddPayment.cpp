//---------------------------------------------------------------------------
#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FAddPayment.h"
#include "FSelectCustomer.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmAddPayment *FrmAddPayment;
//---------------------------------------------------------------------------
__fastcall TFrmAddPayment::TFrmAddPayment(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();

 dataset = DatasetPayment;

 dataset->Database = database;
 dataset->Transaction = transaction;

 QryCustomer->Database = database;
 QryCustomer->Transaction = transaction;

 dataset->Active = true;
 dataset->Insert();

 dataset->FieldByName("PAY_DATE")->AsDateTime = runningDate;

}
//---------------------------------------------------------------------------

void TFrmAddPayment::setCustomerId(int _id)
{
 QryCustomer->Active = false;
 QryCustomer->ParamByName("CUSTID")->AsInteger = _id;
 QryCustomer->Active = true;
 dataset->FieldByName("CUST_ID")->AsInteger = QryCustomer->FieldByName("CUST_ID")->AsInteger;
 showData();

 editPayment->SelectAll();
 editPayment->SetFocus();
}
//---------------------------------------------------------------------------

Currency TFrmAddPayment::getBalance(int cust_id)
{
 //Get customer balance
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Add("SELECT BALANCE FROM GET_CUSTOMER_BALANCE(:CUSTID);");
 query->ParamByName("CUSTID")->AsInteger = QryCustomer->FieldByName("CUST_ID")->AsInteger;
 query->Active = true;

 Currency tmp = query->FieldByName("BALANCE")->AsCurrency;

 delete query;
 return(tmp);
}

void __fastcall TFrmAddPayment::editNameChange(TObject *Sender)
{
 return;

 if(editName->Text.Trim() == QryCustomer->FieldByName("NAME")->AsString.Trim())
  showData();
 else
 {
  ShowMessage("No data!");
  dataset->FieldByName("CUST_ID")->Clear();
  if(!editVatNo->Focused())
   editVatNo->Text = "";
  editOccupation->Text ="";
 }
//---------------------------------------------------------------------------
}

void TFrmAddPayment::showData()
{
 editName->Text = QryCustomer->FieldByName("NAME")->AsString;
 editVatNo->Text = QryCustomer->FieldByName("AFM")->AsString;
 editOccupation->Text = QryCustomer->FieldByName("OCCUPATION")->AsString;

 Currency balance = getBalance(QryCustomer->FieldByName("CUST_ID")->AsInteger);

 lblOldBalance->Caption = CurrToStrF(balance,ffCurrency, 2);
 lblNewBalance->Caption = CurrToStrF(balance,ffCurrency, 2);//CurrToStrF(balance-(double)editPayment->Value,ffCurrency, 2);
}

void __fastcall TFrmAddPayment::editNameKeyDown(TObject *Sender, WORD &Key,
      TShiftState Shift)
{
 if( Key == 120)//F9
 {
  this->Enabled = false;
  TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId,editName->Text, editVatNo->Text);
 }	
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddPayment::editPaymentExit(TObject *Sender)
{
 dataset->FieldByName("VALUE")->AsCurrency = (double)editPayment->Value;
 lblNewBalance->Caption = CurrToStrF(getBalance(QryCustomer->FieldByName("CUST_ID")->AsInteger)-(double)editPayment->Value,ffCurrency, 2);
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddPayment::JvDotNetButton2Click(TObject *Sender)
{
 transaction->Rollback();
 Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddPayment::JvDotNetButton1Click(TObject *Sender)
{
// ShowMessage(dataset->FieldByName("CUST_ID")->AsInteger);
 dataset->FieldByName("PAYMENT_ID")->AsInteger = 0;
 dataset->Post();
 Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddPayment::editVatNoChange(TObject *Sender)
{
 return;

if(editVatNo->Text.Trim() == QryCustomer->FieldByName("AFM")->AsString)
  showData();
 else
 {
  editName->Text = "";
  editOccupation->Text ="";
 }		
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddPayment::editVatNoKeyDown(TObject *Sender, WORD &Key,
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

void __fastcall TFrmAddPayment::editVatNoKeyPress(TObject *Sender, char &Key)
{
// this->Caption = AnsiString((int)Key);
 if( (Key < '0' || Key > '9' )&& Key != 8)
  Key = 0;		
}
//---------------------------------------------------------------------------

