//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FShowNewBalance.h"
#include "FMain.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmShowNewBalance *FrmShowNewBalance;
//---------------------------------------------------------------------------
__fastcall TFrmShowNewBalance::TFrmShowNewBalance(TComponent* Owner, unsigned int invoice_id, double payment)
	: NewSpecialForm(Owner)
{
 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();

 buttonRollback->Visible = false;

 QryInvoice->Database = database;
 QryInvoice->Transaction = transaction;

 QryBalance->Database = database;
 QryBalance->Transaction = transaction;

 QryInvoice->ParamByName("INVOICEID")->AsInteger = invoice_id;
 QryInvoice->Active = true;

 QryBalance->ParamByName("XINVOICE_ID")->AsInteger = invoice_id;
 QryBalance->Active = true;

 lblCustName->Caption = QryInvoice->FieldByName("NAME")->AsString;
 lblCharge->Caption = CurrToStrF(QryInvoice->FieldByName("PRICEWVAT")->AsCurrency,ffCurrency,2);
 lblOldBalance->Caption = CurrToStrF(QryBalance->FieldByName("OLD_BALANCE")->AsCurrency, ffCurrency,2);
 editPayment->Text = payment;
 lblNewBalance->Caption = CurrToStrF(QryBalance->FieldByName("NEW_BALANCE")->AsCurrency - payment, ffCurrency, 2);
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowNewBalance::editPaymentExit(TObject *Sender)
{
 lblNewBalance->Caption = CurrToStrF(QryBalance->FieldByName("NEW_BALANCE")->AsCurrency - (double)editPayment->Value, ffCurrency, 2);
}
//---------------------------------------------------------------------------



void __fastcall TFrmShowNewBalance::JvDotNetButton1Click(TObject *Sender)
{
 TIBQuery *query = new TIBQuery(this);

 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Add("INSERT INTO PAYMENT(CUST_ID,\"VALUE\", PAY_DATE) VALUES(:CUSTID,:VALUE, :DATE);");
 query->ParamByName("CUSTID")->AsInteger = QryInvoice->FieldByName("CUST_ID")->AsInteger;
 query->ParamByName("VALUE")->AsCurrency = (double)editPayment->Value;
 query->ParamByName("DATE")->AsDateTime = runningDate;

 query->ExecSQL();

 delete query;

 Close();
}
//---------------------------------------------------------------------------

