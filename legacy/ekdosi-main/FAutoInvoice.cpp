// ---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FAutoInvoice.h"
#include "SpecialMethods.h"
#include "FPrint.h"
#include "FMailInvoices.h"
#include "CMyData.h"

// ---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvExExtCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "DBAccess"
#pragma link "JvTimer"
#pragma link "MemDS"
#pragma link "Uni"
#pragma link "cxButtons"
#pragma link "cxContainer"
#pragma link "cxControls"
#pragma link "cxEdit"
#pragma link "cxGraphics"
#pragma link "cxLabel"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "JvCheckBox"
#pragma link "JvExStdCtrls"
#pragma link "JvExControls"
#pragma link "JvLED"
#pragma link "cxMemo"
#pragma link "cxTextEdit"
#pragma resource "*.dfm"
TFrmAutoInvoice *FrmAutoInvoice;

// ---------------------------------------------------------------------------
__fastcall TFrmAutoInvoice::TFrmAutoInvoice(TComponent* Owner)
 : NewSpecialForm(Owner)
{
 lastInvCreateTime = time(NULL) - INVOICE_EVERY + 20;
 lastMailTime = time(NULL) - MAIL_EVERY + 9;
 TimerInvoicer->Enabled = true;
 log("Application started..");
 LblStatus->Caption = "Standby..";
}

// ---------------------------------------------------------------------------
void __fastcall TFrmAutoInvoice::TimerInvoicerTimer(TObject *Sender)
{
 LedKeepAlive->Status = !LedKeepAlive->Status;
 TimerInvoicer->Enabled = false;
 // this->Caption = AnsiString(lastRunTime + INVOICE_EVERY)+" > "+AnsiString(time(NULL));

 if ((lastInvCreateTime + INVOICE_EVERY) <= time(NULL))
 {
  if (checkGriniaris->Checked)
  {
   LblStatus->Caption = "Running Griniaris...";
   Application->ProcessMessages();
   runGriniaris();
  }

  if (checkThird->Checked)
  {
   LblStatus->Caption = "Running 3rd invoices...";
   Application->ProcessMessages();
   runThirdInvoices();
  }

  if (checkAssigned->Checked)
  {
   LblStatus->Caption = "Running Assigned invoices...";
   Application->ProcessMessages();
   runAssignedInvoices();
  }

  if (checkMydata->Checked)
  {
   LblStatus->Caption = "Running MyDATA...";
   Application->ProcessMessages();
   runSendMydata();
  }

  lastInvCreateTime = time(NULL);
  if (lastMailTime + MAIL_EVERY <= time(NULL))
   lastMailTime = time(NULL);
 }

 if (checkMail->Checked && (lastMailTime + MAIL_EVERY) <= time(NULL))
 {
  LblStatus->Caption = "Running mail sendout...";
  Application->ProcessMessages();
  mailInvoices();
  lastMailTime = time(NULL);
 }

 LblStatus->Caption = "Standby..";
 TimerInvoicer->Enabled = true;
}
// ---------------------------------------------------------------------------

void TFrmAutoInvoice::runGriniaris()
{
 resetInvoicesQuery();
 try
 {
  Application->ProcessMessages();
  runInvoicing();
 }
 catch (Exception &e)
 {
  log(e.Message);
 }

 closeAllDatasets();
}

void TFrmAutoInvoice::runThirdInvoices()
{
 resetInvoicesQuery();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "and HAS_3RD_INVOICE = 1";
 try
 {
  Application->ProcessMessages();
  runInvoicing();
 }
 catch (Exception &e)
 {
  log(e.Message);
 }

 closeAllDatasets();
}

void TFrmAutoInvoice::runAssignedInvoices()
{
 resetInvoicesQuery();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 3] = "and mi.invoiced = -333";
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "";

 try
 {
  Application->ProcessMessages();
  runInvoicing();
 }
 catch (Exception &e)
 {
  log(e.Message);
 }

 closeAllDatasets();
}

void TFrmAutoInvoice::resetInvoicesQuery()
{
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 3] = "and mi.invoiced = 0";
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "and gkriniaris = 'on'";
}

void TFrmAutoInvoice::closeAllDatasets()
{
 try
 {
  sqlConnection->Connected = false;
  QueryLocalCustomer->Close();
  QueryThirdInvoice->Close();
  QueryInvLines->Close();
  DatasetCustomer->Close();
 }
 catch (...)
 {
 }
}

void TFrmAutoInvoice::invoiceLog(long _invId, AnsiString _message)
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "INSERT INTO AUTO_INVOICE_LOG(CS_INVID, LOG_MESSAGE) VALUES(:INV_ID, :MESSAGE)";
 query->ParamByName("INV_ID")->AsInteger = _invId;
 query->ParamByName("MESSAGE")->AsString = _message;
 query->ExecSQL();
}

void TFrmAutoInvoice::log(AnsiString _message)
{
 MemoLog->Lines->Insert(0, "[" + Now().FormatString("dd/mm/yyyy HH:mm:ss") + "] " + _message);
}

void TFrmAutoInvoice::runInvoicing()
{
 if (!transaction->InTransaction)
  transaction->StartTransaction();

 QueryCustomerDetails = NULL;
 QueryInvoices->Close();
 QueryInvoices->Open();
 QueryInvLines->Open();

 QueryInvoices->Last();
 int invoicesAvailable = QueryInvoices->RecordCount;
 LblInvoicesLeft->Caption = invoicesAvailable;
 if (invoicesAvailable > 0)
  log("Auto-Invoicing started.");
 QueryInvoices->First();

 if (invoicesAvailable > 0)
  sendOneInvoice();

 QueryInvLines->Close();
 QueryInvoices->Close();
 transaction->Commit();

 if (invoicesAvailable > 0)
  log("Auto-Invoicing fnished.");
}

void TFrmAutoInvoice::sendOneInvoice()
{
 bool isInvoice = false;
 AnsiString vatPrefix = "";
 AnsiString vatField = "";
 // LblStatus->Caption = "Sending "+AnsiString(QueryInvoices->FieldByName("id")->AsString) + " -- "+ QueryInvoices->FieldByName("companyname")->AsString;
 Application->ProcessMessages();

 syncAllDatasets(isInvoice, vatPrefix, vatField);

 if (!isInvoice)
  invoiceAutoSupress(QueryInvoices->FieldByName("id")->AsInteger);

 if (!checkCustomerDataIntegrity(isInvoice, vatPrefix, vatField))
  return;

 long invId = addInvoice(isInvoice);
 markCSInvoice(QueryInvoices->FieldByName("id")->AsInteger, invId);
}

void TFrmAutoInvoice::syncAllDatasets(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField)
{
 QueryThirdInvoice->Close();
 QueryLocalCustomer->Close();

 if (QueryInvoices->FieldByName("HAS_3RD_INVOICE")->AsInteger == 1)
 {
  QueryThirdInvoice->ParamByName("invoiceid")->AsInteger = QueryInvoices->FieldByName("id")->AsInteger;
  QueryThirdInvoice->Open();
  _isInvoice = QueryThirdInvoice->FieldByName("isReceipt")->AsString != "1";
  QueryCustomerDetails = QueryThirdInvoice;
  _vatPrefix = "TH";
  _vatField = "contactid";
 }
 else
 {
  _isInvoice = QueryInvoices->FieldByName("toinvoice")->AsString == "on";
  QueryCustomerDetails = QueryInvoices;
  _vatPrefix = "U";
  _vatField = "userid";
 }

 if (_isInvoice)
  QueryLocalCustomer->ParamByName("AFM")->AsString = QueryCustomerDetails->FieldByName("vatno")->AsString.Trim();
 else
  QueryLocalCustomer->ParamByName("AFM")->AsString = _vatPrefix + QueryCustomerDetails->FieldByName(_vatField)->AsString.Trim();
 QueryLocalCustomer->Open();
}

bool TFrmAutoInvoice::checkCustomerDataIntegrity(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField)
{
 AnsiString vatCode = QueryCustomerDetails->FieldByName("vatno")->AsString.Trim();
 if (_isInvoice && !checkVatCode(vatCode))
 {
  invoiceAutoSupress(QueryInvoices->FieldByName("id")->AsInteger);
  log("Invoice:" + AnsiString(QueryInvoices->FieldByName("id")->AsInteger) + " -- Not vaild VAT Code: " + vatCode);
  return (false);
 }

 if (QueryLocalCustomer->RecordCount == 0)
 {
  addNewCustomer(_isInvoice, _vatPrefix, _vatField);
  syncAllDatasets(_isInvoice, _vatPrefix, _vatField);
  if (QueryLocalCustomer->FieldByName("CUST_ID")->AsInteger == 0)
  {
   invoiceLog(QueryInvoices->FieldByName("id")->AsInteger, "Customer added for the invoice doens't exist!");
   invoiceAutoSupress(QueryInvoices->FieldByName("id")->AsInteger);
   return (false);
  }
 }

 amendCustomer(_isInvoice);

 return (true);
}

void TFrmAutoInvoice::invoiceAutoSupress(long _invoiceId)
{
 markCSInvoice(_invoiceId, -1000);
}

void TFrmAutoInvoice::markCSInvoice(long _invoiceId, long _mark)
{
 TUniQuery *query = new TUniQuery(this);
 query->Connection = sqlConnection;

 query->SQL->Add("UPDATE tblinvoices set invoiced = :mark where id = :id;");
 query->ParamByName("id")->AsInteger = _invoiceId;
 query->ParamByName("mark")->AsInteger = _mark;
 query->ExecSQL();
}

void TFrmAutoInvoice::addNewCustomer(bool &_isInvoice, AnsiString &_vatPrefix, AnsiString &_vatField)
{
 DatasetCustomer->Open();
 DatasetCustomer->Insert();

 if (!_isInvoice)
 {
  DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString)
   .SubString(0, 49);
  DatasetCustomer->FieldByName("OCCUPATION")->AsString = "ÉÄÉÙÔÇÓ";
  DatasetCustomer->FieldByName("AFM")->AsString = _vatPrefix + QueryCustomerDetails->FieldByName(_vatField)->AsString;
  DatasetCustomer->FieldByName("TYPE")->AsString = "INDIVIDUAL";
 }
 else
 {
  if (removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString.Trim()) == "")
   DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString)
	.SubString(0, 49);
  else
   DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString).SubString(0, 49);

  DatasetCustomer->FieldByName("OCCUPATION")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("occupation")->AsString.SubString(0, 58));
  DatasetCustomer->FieldByName("AFM")->AsString = QueryCustomerDetails->FieldByName("vatno")->AsString;
  DatasetCustomer->FieldByName("TAXOFFICE")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("taxoffice")->AsString);
  DatasetCustomer->FieldByName("TYPE")->AsString = "BUSINESS";
 }
 DatasetCustomer->FieldByName("ADDRESS1")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("address1")->AsString);
 DatasetCustomer->FieldByName("ADDRESS2")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("address2")->AsString);
 DatasetCustomer->FieldByName("CITY")->AsString = QueryCustomerDetails->FieldByName("city")->AsString;
 DatasetCustomer->FieldByName("POSTCODE")->AsString = QueryCustomerDetails->FieldByName("postcode")->AsString;
 DatasetCustomer->FieldByName("PHONE1")->AsString = QueryCustomerDetails->FieldByName("phonenumber")->AsString;
 DatasetCustomer->FieldByName("EMAIL")->AsString = QueryCustomerDetails->FieldByName("email")->AsString;
 if (DatasetCustomer->FieldByName("EMAIL")->AsString == "")
  DatasetCustomer->FieldByName("EMAIL")->AsString = QueryInvoices->FieldByName("email")->AsString;
 DatasetCustomer->FieldByName("COUNTRY")->AsString = QueryCustomerDetails->FieldByName("country")->AsString;

 DatasetCustomer->Post();
 log("Customer created " + removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString));
 DatasetCustomer->Close();
}

void TFrmAutoInvoice::amendCustomer(bool &_isInvoice)
{
 DatasetCustomer->ParamByName("CUST_ID")->AsInteger = QueryLocalCustomer->FieldByName("CUST_ID")->AsInteger;
 DatasetCustomer->Open();

 bool dataChanged = false;
 TUniQuery *queryCSCustomer;

 if (!_isInvoice)
 {
  DatasetCustomer->FieldByName("NAME")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString).SubString
   (0, 49) ? dataChanged = true : 1;
 }
 else
 {
  if (removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString.Trim()) == "")
   DatasetCustomer->FieldByName("NAME")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString).SubString
	(0, 49) ? dataChanged = true : 1;
  else
   DatasetCustomer->FieldByName("NAME")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString).SubString(0, 49) ? dataChanged = true : 1;
  DatasetCustomer->FieldByName("OCCUPATION")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("occupation")->AsString).SubString(0, 58) ? dataChanged = true : 1;
  DatasetCustomer->FieldByName("TAXOFFICE")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("taxoffice")->AsString) ? dataChanged = true : 1;
 }

 DatasetCustomer->FieldByName("ADDRESS1")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("address1")->AsString) ? dataChanged = true : 1;
 DatasetCustomer->FieldByName("ADDRESS2")->AsString != removeAmbersand(QueryCustomerDetails->FieldByName("address2")->AsString) ? dataChanged = true : 1;

 DatasetCustomer->FieldByName("CITY")->AsString != QueryCustomerDetails->FieldByName("city")->AsString ? dataChanged = true : 1;
 DatasetCustomer->FieldByName("POSTCODE")->AsString != QueryCustomerDetails->FieldByName("postcode")->AsString ? dataChanged = true : 1;

 DatasetCustomer->FieldByName("PHONE1")->AsString != QueryCustomerDetails->FieldByName("phonenumber")->AsString ? dataChanged = true : 1;
 if (QueryCustomerDetails->FieldByName("email")->AsString.Trim() != "")
  DatasetCustomer->FieldByName("EMAIL")->AsString != QueryCustomerDetails->FieldByName("email")->AsString ? dataChanged = true : 1;
 else
  DatasetCustomer->FieldByName("EMAIL")->AsString != QueryInvoices->FieldByName("email")->AsString ? dataChanged = true : 1;
 DatasetCustomer->FieldByName("COUNTRY")->AsString != QueryCustomerDetails->FieldByName("country")->AsString ? dataChanged = true : 1;

 if (dataChanged)
 {
  DatasetCustomer->Edit();
  if (!_isInvoice)
  {
   DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString)
	.SubString(0, 49);
  }
  else
  {
   if (removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString.Trim()) == "")
	DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("lastname")->AsString + " " + QueryCustomerDetails->FieldByName("firstname")->AsString)
	 .SubString(0, 49);
   else
	DatasetCustomer->FieldByName("NAME")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("companyname")->AsString).SubString(0, 49);
   DatasetCustomer->FieldByName("OCCUPATION")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("occupation")->AsString.SubString(0, 58));
   DatasetCustomer->FieldByName("TAXOFFICE")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("taxoffice")->AsString);
  }

  DatasetCustomer->FieldByName("ADDRESS1")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("address1")->AsString);
  DatasetCustomer->FieldByName("ADDRESS2")->AsString = removeAmbersand(QueryCustomerDetails->FieldByName("address2")->AsString);

  DatasetCustomer->FieldByName("CITY")->AsString = QueryCustomerDetails->FieldByName("city")->AsString;
  DatasetCustomer->FieldByName("POSTCODE")->AsString = QueryCustomerDetails->FieldByName("postcode")->AsString;

  DatasetCustomer->FieldByName("PHONE1")->AsString = QueryCustomerDetails->FieldByName("phonenumber")->AsString;
  DatasetCustomer->FieldByName("EMAIL")->AsString = QueryCustomerDetails->FieldByName("email")->AsString;
  if (DatasetCustomer->FieldByName("EMAIL")->AsString == "")
   DatasetCustomer->FieldByName("EMAIL")->AsString = QueryInvoices->FieldByName("email")->AsString;
  DatasetCustomer->FieldByName("COUNTRY")->AsString = QueryCustomerDetails->FieldByName("country")->AsString;
  AnsiString custName = DatasetCustomer->FieldByName("NAME")->AsString + " - " + DatasetCustomer->FieldByName("AFM")->AsString;
  DatasetCustomer->Post();
  log("Updated customer details for customer " + custName);
 }
}

long TFrmAutoInvoice::addInvoice(bool _isInvoice)
{
 long invId = 0;
 AnsiString vatNo, companyName;

 if (countInvoiceLines() == 0)
 {
  invoiceAutoSupress(QueryInvoices->FieldByName("id")->AsInteger);
  log("Suppressed invId " + AnsiString(QueryInvoices->FieldByName("id")->AsInteger) + " due to empty invoice lines.");
  return (-1000);
 }

 invId = createInvId(_isInvoice, vatNo, companyName);

 addInvoiceLines(invId);
 amendInvoiceValues(invId);
 transaction->CommitRetaining();

 if (sentInvoiceToMyData(invId, companyName + " VAT:" + vatNo))
  TFrmPrint * frmPrint = new TFrmPrint(Owner, transaction, getInvFilename(_isInvoice), "INVOICE_ID", invId, true);
 else
  log("Deferring printing until invoice is sent to myDATA");
 AnsiString invWord = "";

 invWord = _isInvoice ? "Invoice " : "Receipt ";

 log(invWord + AnsiString(QueryInvoices->FieldByName("id")->AsInteger) + " created for " + companyName + " - " + vatNo);

 return (invId);
}

long TFrmAutoInvoice::createInvId(bool _isInvoice, AnsiString &_vatNo, AnsiString &_companyName)
{
 long invoiceId = generateNewId("GEN_INVOICE_ID");
 auto_ptr<TIBQuery>query = getSmartNewQuery();
 AnsiString invType;
 if (_isInvoice)
  invType = "ÔÐÕ";
 else
  invType = "ÁÐÕ";

 query->SQL->Text =
  "INSERT INTO INVOICE(INVOICE_ID, PRINTED, CUST_ID, INVTYPE, INVDATE, DELIVERYDATE, DISTRAIM_ID, DELMETHOD_ID, PAYMETH_ID, COMPANY_NAME, VAT_NO, OCCUPATION, ADDRESS1, ADDRESS2, CITY, POSTCODE, COUNTRY) \
																						VALUES(:INVOICE_ID, 0, :CUST_ID, :INVTYPE, 'today', 'today', :DISTRAIM_ID, :DELMETHOD_ID, :PAYMETH_ID, :COMPANY_NAME, :VAT_NO, :OCCUPATION, :ADDRESS1, :ADDRESS2, :CITY, :POSTCODE, :COUNTRY)";
 query->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
 query->ParamByName("CUST_ID")->AsInteger = DatasetCustomer->FieldByName("CUST_ID")->AsInteger;
 query->ParamByName("INVTYPE")->AsString = invType;
 query->ParamByName("DISTRAIM_ID")->AsInteger = 2;
 query->ParamByName("DELMETHOD_ID")->AsInteger = 1;
 query->ParamByName("PAYMETH_ID")->AsInteger = 2;
 query->ParamByName("COMPANY_NAME")->AsString = DatasetCustomer->FieldByName("NAME")->AsString;
 query->ParamByName("VAT_NO")->AsString = DatasetCustomer->FieldByName("AFM")->AsString;
 query->ParamByName("OCCUPATION")->AsString = DatasetCustomer->FieldByName("OCCUPATION")->AsString.SubString(0, 59);
 query->ParamByName("ADDRESS1")->AsString = DatasetCustomer->FieldByName("ADDRESS1")->AsString;
 query->ParamByName("ADDRESS2")->AsString = DatasetCustomer->FieldByName("ADDRESS2")->AsString;
 query->ParamByName("ADDRESS2")->AsString = DatasetCustomer->FieldByName("ADDRESS2")->AsString;
 query->ParamByName("CITY")->AsString = DatasetCustomer->FieldByName("CITY")->AsString;
 query->ParamByName("POSTCODE")->AsString = DatasetCustomer->FieldByName("POSTCODE")->AsString;
 query->ParamByName("COUNTRY")->AsString = DatasetCustomer->FieldByName("COUNTRY")->AsString;
 query->ExecSQL();

 _vatNo = DatasetCustomer->FieldByName("AFM")->AsString;
 _companyName = DatasetCustomer->FieldByName("NAME")->AsString;
 return (invoiceId);
}

long TFrmAutoInvoice::countInvoiceLines()
{
 long retVal = 0;
 QueryInvLines->First();
 while (!QueryInvLines->Eof)
 {
  if (QueryInvLines->FieldByName("type")->AsString != "AddFunds" && QueryInvLines->FieldByName("type")->AsString != "Invoice" && QueryInvLines->FieldByName("amount")->AsFloat > 0.0)
   retVal++;
  QueryInvLines->Next();
 }

 QueryInvLines->First();
 return (retVal);
}

void TFrmAutoInvoice::addInvoiceLines(long _invoiceId)
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "INSERT INTO INVLINES(INVOICE_ID, PRODUCT_ID, QTY, PRICE_PER_ITEM, VATPERCENT, PRICE, PRICEWVAT, PRODUCT_DESCR)  \
																						VALUES (:INVOICE_ID, :PRODUCT_ID, 1, :PRICE_PER_ITEM, :VATPERCENT, :PRICE, :PRICEWVAT, :PRODUCT_DESCR)";
 query->ParamByName("INVOICE_ID")->AsInteger = _invoiceId;

 QueryInvLines->First();

 while (!QueryInvLines->Eof)
 {
  if (QueryInvLines->FieldByName("type")->AsString == "AddFunds" || QueryInvLines->FieldByName("type")->AsString == "Invoice" || QueryInvLines->FieldByName("amount")->AsFloat == 0.0)
  {
   QueryInvLines->Next();
   continue;
  }

  query->ParamByName("PRODUCT_ID")->AsInteger = getCurProductIdFromInvlineType();

  query->ParamByName("VATPERCENT")->AsFloat = getVatPercent();
  if (QueryInvLines->FieldByName("taxed")->AsInteger == 1)
   query->ParamByName("PRICE_PER_ITEM")->AsFloat = QueryInvLines->FieldByName("amount")->AsFloat;
  else
   query->ParamByName("PRICE_PER_ITEM")->AsFloat = QueryInvLines->FieldByName("amount")->AsFloat / (1 + (query->ParamByName("VATPERCENT")->AsFloat / 100.00));

  query->ParamByName("PRICE")->AsFloat = query->ParamByName("PRICE_PER_ITEM")->AsFloat;
  query->ParamByName("PRICEWVAT")->AsFloat = query->ParamByName("PRICE")->AsFloat + (query->ParamByName("PRICE")->AsFloat * (query->ParamByName("VATPERCENT")->AsFloat / 100.0));

  AnsiString description = QueryInvLines->FieldByName("description")->AsString;
  if(QueryInvLines->FieldByName("description")->AsString.Pos("\n") > 0)
   description = QueryInvLines->FieldByName("description")->AsString.SubString(0, QueryInvLines->FieldByName("description")->AsString.Pos("\n")-1);
  query->ParamByName("PRODUCT_DESCR")->AsString = description.SubString(0,250);

  Application->ProcessMessages();
  QueryInvLines->Next();

  if (QueryInvLines->FieldByName("type")->AsString == "PromoHosting")
  {
   query->ParamByName("PRICE_PER_ITEM")->AsFloat = query->ParamByName("PRICE_PER_ITEM")->AsFloat + QueryInvLines->FieldByName("amount")->AsFloat;
   query->ParamByName("PRICE")->AsFloat = query->ParamByName("PRICE_PER_ITEM")->AsFloat;
   query->ParamByName("PRICEWVAT")->AsFloat = query->ParamByName("PRICE")->AsFloat + (query->ParamByName("PRICE")->AsFloat * (query->ParamByName("VATPERCENT")->AsFloat / 100.0));
   QueryInvLines->Next();
  }
  query->ExecSQL();
 }
}

long TFrmAutoInvoice::getCurProductIdFromInvlineType()
{
 long retVal = 0;

 if (QueryInvLines->FieldByName("type")->AsString.SubString(1, 6) == "Domain")
  retVal = 1002;
 else if (QueryInvLines->FieldByName("type")->AsString == "Hosting" || QueryInvLines->FieldByName("type")->AsString == "Upgrade" || QueryInvLines->FieldByName("type")->AsString == "Addon")
  retVal = 1001;
 else
  retVal = 1003;

 return (retVal);
}

double TFrmAutoInvoice::getVatPercent()
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "SELECT \"VALUE\" AS VAT_VALUE FROM VAT_CATEGORY WHERE DEFAULT_CAT = 1";
 query->Open();
 return (query->FieldByName("VAT_VALUE")->AsFloat);
}

AnsiString TFrmAutoInvoice::getInvFilename(bool _isInvoice)
{
 AnsiString invType = "ÔÐÕ";
 if (!_isInvoice)
  invType = "ÁÐÕ";

 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "SELECT FRM_FILENAME FROM INVTYPE WHERE INVTYPE_ID = :INVTYPE_ID";
 query->ParamByName("INVTYPE_ID")->AsString = invType;
 query->Open();

 return (query->FieldByName("FRM_FILENAME")->AsString);
}

void TFrmAutoInvoice::amendInvoiceValues(long _invoiceId)
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "EXECUTE PROCEDURE CALCULATE_INVOICE_VALUES(:INVOICE_ID);";
 query->ParamByName("INVOICE_ID")->AsInteger = _invoiceId;
 query->ExecSQL();
}

void TFrmAutoInvoice::mailInvoices()
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();
 long completionCode = 0;
 AnsiString completionMessage;

 query->SQL->Text = "SELECT FIRST 5 * FROM INVOICE WHERE COALESCE(MAILED, 0) = 0";
 query->Open();
 if (query->RecordCount > 0)
  log("Invoice send out started.");
 while (!query->Eof)
 {
  completionCode = 0;
  completionMessage = "";
  TFrmMailInvoices *frmMailInvoice = new TFrmMailInvoices(Owner, query->FieldByName("INVOICE_ID")->AsInteger, true);
  frmMailInvoice->setCompletenessPtr(&completionCode, &completionMessage);

  while (completionCode == 0)
  {
   Sleep(500);
   Application->ProcessMessages();
  }

  log(completionMessage);
  query->Next();
 }

 if (completionMessage != "")
  log("Mail send out finished!");
 transaction->Commit();
}

void TFrmAutoInvoice::runSendMydata()
{
 auto_ptr<TIBQuery>query = getSmartNewQuery();

 query->SQL->Text = "SELECT FIRST 1 INVOICE.*, CUSTOMER.NAME AS CUSTNAME FROM INVOICE \
																					LEFT JOIN CUSTOMER ON INVOICE.CUST_ID = CUSTOMER.CUST_ID \
																						WHERE COALESCE(INVOICE.MYDATA_SENT, 0) = 0 \
																						AND INVOICE.INVTYPE != 'INV' \
																						ORDER BY INVOICE_ID ASC";
 query->Open();
 if (query->RecordCount == 0)
  return;

 std::auto_ptr<MyData>myData(new MyData());
 std::pair<bool, AnsiString>res = myData->sendInvoice(query->FieldByName("INVOICE_ID")->AsInteger);

 if (res.first == true)
 {
  log("Finished sending MyDATA invoice of " + query->FieldByName("INVCODE")->AsString + " - " + query->FieldByName("CUSTNAME")->AsString);
  bool isInvoice = query->FieldByName("INVTYPE")->AsString == "ÔÐÕ" ? true : false;
  TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction, getInvFilename(isInvoice), "INVOICE_ID", query->FieldByName("INVOICE_ID")->AsInteger, true, false);
 }
 else
  log("Failed to send MyDATA invoice of " + query->FieldByName("INVCODE")->AsString + " - " + query->FieldByName("CUSTNAME")->AsString + " - Error:" + res.second);
}

bool TFrmAutoInvoice::sentInvoiceToMyData(int _invoiceId, AnsiString _description)
{
 std::auto_ptr<MyData>myData(new MyData());
 std::pair<bool, AnsiString>res = myData->sendInvoice(_invoiceId);

 if (!res.first)
 {
  log("Failed to send MyDATA invoice of " + _description + " - Error:" + res.second);
  return (false);
 }

 return (true);
}
