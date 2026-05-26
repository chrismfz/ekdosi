// ---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FCSConnect.h"
#include "FMain.h"
#include "RegistryAccess.h"
#include "FSelectCustomer.h"
#include "FAddCustomer.h"
#include "FAddInvoice.h"
#include "FShowCustomers.h"
#include "FMailInvoices.h"
#include "FupdateCustomerDetails.h"
#include "AppController.h"
#include "SpecialMethods.h"
// ---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma resource "*.dfm"
TFrmCSConnect *FrmCSConnect;

// ---------------------------------------------------------------------------
__fastcall TFrmCSConnect::TFrmCSConnect(TComponent* Owner) : NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);
 if(reg->loadCustomBool("checkEmail") == 1)
		checkEmail->Checked = true;
 delete reg;

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 sqlConnection->Connected = true;

 formVisible = false;

 QueryInvoices->Open();
 QueryInvLines->Open();
 QueryLocalCustomer->Open();
 QueryThirdInvoice->Open();

 if(PageControl->ActivePageIndex == 1)
  QueryInvLines->Open();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::QueryInvoicesAfterScroll(TDataSet *DataSet)
{
	AnsiString vatField = "vatno";
	AnsiString idPrefix = "";

	if(QueryInvoices->FieldByName("HAS_3RD_INVOICE")->AsInteger == 1)
	{
  lblInvoiceThird->Visible = true;
		if(formVisible)
  {
   QueryThirdInvoice->Close();
   QueryThirdInvoice->ParamByName("invoiceid")->AsInteger = QueryInvoices->FieldByName("id")->AsInteger;
   QueryThirdInvoice->Open();
			QueryLocalCustomer->Close();

			if(QueryThirdInvoice->FieldByName("isReceipt")->AsString == "1")
			{
				vatField = "contactid";
				idPrefix = "TH";
			}
			QueryLocalCustomer->ParamByName("AFM")->AsString = idPrefix+QueryThirdInvoice->FieldByName(vatField)->AsString.Trim();
   QueryLocalCustomer->Open();
  }
 }
 else
 {
  lblInvoiceThird->Visible = false;
  if(formVisible)
  {
   QueryThirdInvoice->Close();
			QueryLocalCustomer->Close();
			if(QueryInvoices->FieldByName("toinvoice")->AsString != "on")
			{
				vatField = "userid";
				idPrefix = "U";
   }
			QueryLocalCustomer->ParamByName("AFM")->AsString = idPrefix+QueryInvoices->FieldByName(vatField)->AsString.Trim();
   QueryLocalCustomer->Open();
  }
 }
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::btnConnectClick(TObject *Sender)
{
 AnsiString vat;
 vat = "";

 if (QueryInvoices->FieldByName("toinvoice")->AsString == "on")
  vat = QueryInvoices->FieldByName("vatno")->AsString;
 else
  vat = QueryInvoices->FieldByName("id")->AsString;

 TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId, "", vat);
}
// ---------------------------------------------------------------------------

void TFrmCSConnect::setCustomerId(int _id)
{
 TIBQuery *qry = new TIBQuery(this);

 qry->Database = database;
 qry->Transaction = transaction;

 qry->SQL->Add("EXECUTE PROCEDURE UPDATE_CS_CUST_CONNECTION(:CS_ID, :CUST_ID)");

 qry->ParamByName("CS_ID")->AsInteger = QueryInvoices->FieldByName("userid")->AsInteger;
 qry->ParamByName("CUST_ID")->AsInteger = _id;
 qry->ExecSQL();

 refreshDataset(QueryInvoices);
 delete qry;
}

void TFrmCSConnect::refreshDataset(TUniQuery *_dataset)
{
 TByteDynArray bookmark;
 bookmark = _dataset->GetBookmark();
 try
 {
  _dataset->Active = false;
  _dataset->Active = true;
 }catch(Exception &e)
 {
  ;
 }

 _dataset->GotoBookmark(bookmark);
}

AnsiString TFrmCSConnect::findInvType()
{
//				QueryInvoices->FieldByName("toinvoice")->AsString == "" && QueryInvoices->FieldByName("vatno")->AsString == "" )
	if(QueryLocalCustomer->ParamByName("AFM")->AsString == "")
		return ("ΑΠΥ");
 else
  return ("ΤΠΥ");
}

void __fastcall TFrmCSConnect::cmdSuppressClick(TObject *Sender)
{
	AnsiString ids;
// ids = QueryInvoices->FieldByName("id")->AsInteger;

 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  if(i > 0)
   ids += ", ";
  ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
  Application->ProcessMessages();
  if(QueryInvoices->FieldByName("gkriniaris")->AsString == "on")
  {
   int ans = showMessage("Invoice ASAP on ID:"+ QueryInvoices->FieldByName("id")->AsString+ "\nContinue?", MB_ICONEXCLAMATION | MB_YESNO);
   if(ans == 6)
	ids += QueryInvoices->FieldByName("id")->AsInteger;
  }
  else
   ids += QueryInvoices->FieldByName("id")->AsInteger;
 }

 if(ids == "")
  return;

	TUniQuery *query = new TUniQuery(this);

	query->Connection = sqlConnection;


	query->SQL->Add("UPDATE tblinvoices set invoiced = -1 where id IN ( " + ids + ");");
//  ShowMessage(query->SQL->Text);
	query->ExecSQL();

 ToolRefresh1->Click();
 delete query;
}
// ---------------------------------------------------------------------------

void TFrmCSConnect::updateCustomerDetails()
{
	int custId = QueryLocalCustomer->FieldByName("CUST_ID")->AsInteger;
	bool isInvoice = true;

 if(custId == 0)
 {
		TFrmAddCustomer *frmAddCustomer = addCustomer();

		waitUntilClosed(frmAddCustomer);
		return;
 }

	TUniQuery *queryCSCustomer;
	if(QueryInvoices->FieldByName("HAS_3RD_INVOICE")->AsInteger != 1)
	{
		queryCSCustomer = QueryInvoices;
		isInvoice = QueryInvoices->FieldByName("toinvoice")->AsString == "on";
	}
	else
	{
		queryCSCustomer = QueryThirdInvoice;
		isInvoice = QueryThirdInvoice->FieldByName("isReceipt")->AsString != "1";
	}

	TIBQuery *query = QueryAcceptedCustomer; //QueryLocalCustomer;
	bool dataChanged = false;

	if (!isInvoice)
	{
		query->FieldByName("NAME")->AsString != removeAmbersand(queryCSCustomer->FieldByName("lastname")->AsString + " " + queryCSCustomer->FieldByName("firstname")->AsString) ? dataChanged = true : 1;
		query->FieldByName("OCCUPATION")->AsString != "ΙΔΙΩΤΗΣ" ? dataChanged = true : 1;
	}
	else
	{
		query->FieldByName("NAME")->AsString != removeAmbersand(queryCSCustomer->FieldByName("companyname")->AsString) ? dataChanged = true : 1;
		query->FieldByName("OCCUPATION")->AsString != removeAmbersand(queryCSCustomer->FieldByName("occupation")->AsString) ? dataChanged = true : 1;
		query->FieldByName("TAXOFFICE")->AsString != queryCSCustomer->FieldByName("taxoffice")->AsString ? dataChanged = true : 1;
	}

	query->FieldByName("ADDRESS1")->AsString != removeAmbersand(queryCSCustomer->FieldByName("address1")->AsString) ? dataChanged = true : 1;
	query->FieldByName("ADDRESS2")->AsString != removeAmbersand(queryCSCustomer->FieldByName("address2")->AsString) ? dataChanged = true : 1;

	query->FieldByName("CITY")->AsString != queryCSCustomer->FieldByName("city")->AsString ? dataChanged = true : 1;
	query->FieldByName("POSTCODE")->AsString != queryCSCustomer->FieldByName("postcode")->AsString ? dataChanged = true : 1;

	query->FieldByName("PHONE1")->AsString != queryCSCustomer->FieldByName("phonenumber")->AsString ? dataChanged = true : 1;
	query->FieldByName("EMAIL")->AsString != queryCSCustomer->FieldByName("email")->AsString ? dataChanged = true : 1;
	query->FieldByName("COUNTRY")->AsString != queryCSCustomer->FieldByName("country")->AsString ? dataChanged = true : 1;

	if(dataChanged)
 {
  TFrmUpdateCustomerDetails *frmUpdateDetails = new TFrmUpdateCustomerDetails(Owner, custId);
		frmUpdateDetails->editCompany->Text = removeAmbersand(queryCSCustomer->FieldByName("companyname")->AsString.SubString(0,49));
  frmUpdateDetails->editAddress1->Text = removeAmbersand(queryCSCustomer->FieldByName("address1")->AsString);
  frmUpdateDetails->editAddress2->Text = removeAmbersand(queryCSCustomer->FieldByName("address2")->AsString);
  frmUpdateDetails->editCity->Text = queryCSCustomer->FieldByName("city")->AsString;
  frmUpdateDetails->editPostCode->Text = queryCSCustomer->FieldByName("postcode")->AsString;
  frmUpdateDetails->editCountry->Text = queryCSCustomer->FieldByName("country")->AsString;
  frmUpdateDetails->editPhone1->Text = queryCSCustomer->FieldByName("phonenumber")->AsString;
  frmUpdateDetails->editEmail->Text = queryCSCustomer->FieldByName("email")->AsString;
		frmUpdateDetails->editTaxOffice->Text = queryCSCustomer->FieldByName("taxoffice")->AsString;
		frmUpdateDetails->editOccupation->Text = removeAmbersand(queryCSCustomer->FieldByName("occupation")->AsString.SubString(0,59));
  waitUntilClosed(frmUpdateDetails);
	}
}

TFrmAddCustomer* TFrmCSConnect::addCustomer()
{
	TFrmAddCustomer *frmAddCustomer = new TFrmAddCustomer(Owner);
	TUniQuery *queryCSCustomer;
	bool isInvoice = false;
	AnsiString vatPrefix = "";
	AnsiString vatField = "";


	while (frmAddCustomer->datasetNew == 0)
 {
  Application->ProcessMessages();
  Sleep(300);
 }

	if(QueryInvoices->FieldByName("HAS_3RD_INVOICE")->AsInteger != 1)
	{
		queryCSCustomer = QueryInvoices;
		if(queryCSCustomer->FieldByName("toinvoice")->AsString == "on")
			isInvoice = true;
		else
		{
			vatPrefix = "U";
			vatField = "userid";
		}
 }
	else
 {
  queryCSCustomer = QueryThirdInvoice;
  if(queryCSCustomer->FieldByName("isReceipt")->AsString != "1")
			isInvoice = true;
		else
		{
			vatPrefix = "TH";
			vatField = "contactid";
		}
	}

 if (frmAddCustomer->datasetNew->State == dsInsert)
	{
		if (!isInvoice)
  {
			frmAddCustomer->datasetNew->FieldByName("NAME")->AsString = removeAmbersand(queryCSCustomer->FieldByName("lastname")->AsString + " " + queryCSCustomer->FieldByName("firstname")->AsString);
   frmAddCustomer->datasetNew->FieldByName("OCCUPATION")->AsString = "ΙΔΙΩΤΗΣ";
			frmAddCustomer->datasetNew->FieldByName("AFM")->AsString = vatPrefix + queryCSCustomer->FieldByName(vatField)->AsString;
			frmAddCustomer->ComboType->ItemIndex = 1;
		}
		else
		{
			frmAddCustomer->datasetNew->FieldByName("NAME")->AsString = removeAmbersand(queryCSCustomer->FieldByName("companyname")->AsString.SubString(0,49));
   frmAddCustomer->datasetNew->FieldByName("OCCUPATION")->AsString = removeAmbersand(queryCSCustomer->FieldByName("occupation")->AsString.SubString(0,59));
   frmAddCustomer->datasetNew->FieldByName("AFM")->AsString = queryCSCustomer->FieldByName("vatno")->AsString;
			frmAddCustomer->datasetNew->FieldByName("TAXOFFICE")->AsString = queryCSCustomer->FieldByName("taxoffice")->AsString;
			frmAddCustomer->ComboType->ItemIndex = 0;
  }

		frmAddCustomer->datasetNew->FieldByName("ADDRESS1")->AsString = removeAmbersand(queryCSCustomer->FieldByName("address1")->AsString);
  frmAddCustomer->datasetNew->FieldByName("ADDRESS2")->AsString = removeAmbersand(queryCSCustomer->FieldByName("address2")->AsString);
  frmAddCustomer->datasetNew->FieldByName("CITY")->AsString = queryCSCustomer->FieldByName("city")->AsString;
  frmAddCustomer->datasetNew->FieldByName("POSTCODE")->AsString = queryCSCustomer->FieldByName("postcode")->AsString;
  frmAddCustomer->datasetNew->FieldByName("PHONE1")->AsString = queryCSCustomer->FieldByName("phonenumber")->AsString;
  frmAddCustomer->datasetNew->FieldByName("EMAIL")->AsString = queryCSCustomer->FieldByName("email")->AsString;
		frmAddCustomer->datasetNew->FieldByName("COUNTRY")->AsString = queryCSCustomer->FieldByName("country")->AsString;
 }
 return(frmAddCustomer);
}

bool TFrmCSConnect::isInvoiceThirdSelected()
{
 if(ViewCSInvoice->DataController->GetSelectedCount() <= 1)
 return(false);

 int initialIndex = ViewCSInvoice->DataController->FocusedRowIndex;
 bool retVal = false;
 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
  if(QueryInvoices->FieldByName("HAS_3RD_INVOICE")->AsInteger == 1)
  {
   retVal = true;
   break;
  }
 }

 ViewCSInvoice->DataController->FocusedRowIndex = initialIndex;
 return(retVal);
}

void __fastcall TFrmCSConnect::cmdCustomerAddClick(TObject *Sender)
{
 addCustomer();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::cmdInvoiceClick(TObject *Sender)
{
 updateCustomerDetails();

	QueryInvoicesAfterScroll(QueryInvoices);

	if(isInvoiceThirdSelected())
 {
  showMessage("Η έκδοση ακυρώθηκε. Υπάρχουν παραστατικά σε τρίτους!",MB_ICONERROR);
  return;
 }

 TFrmAddInvoice *frmAddInvoice = new TFrmAddInvoice(Owner, findInvType());

 int productId = 0;
 frmAddInvoice->setCustomerId(QueryLocalCustomer->FieldByName("CUST_ID")->AsInteger);
 prepareProducts();

// ShowMessage("Focused:"+AnsiString(ViewCSInvoice->DataController->GetSelectedCount()));
 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
  Application->ProcessMessages();
  QueryInvLines->First();

  while (!QueryInvLines->Eof)
  {
   if (QueryInvLines->FieldByName("type")->AsString == "Hosting")
				productId = 1001;
			else if (QueryInvLines->FieldByName("type")->AsString.SubString(1,6) == "Domain")
				productId = 1002;
			else if (QueryInvLines->FieldByName("type")->AsString == "addon")
				productId = 1003;
			else
				productId = 1003;

   frmAddInvoice->addInvLine(productId, QueryInvLines->FieldByName("description")->AsString, 1, QueryInvLines->FieldByName("amount")->AsCurrency, QueryInvLines->FieldByName("taxed")->AsInteger);
   QueryInvLines->Next();
  }
 }
 frmAddInvoice->setNotifier(setAllCreatedInvsId);

 this->Enabled = false;
 try
 {
  while (frmAddInvoice->Showing == true)
  {
   Application->ProcessMessages();
   Sleep(100);
  }
 }catch(...) { }

	this->Enabled = true;

 ToolRefresh1->Click();
}
// ---------------------------------------------------------------------------

void TFrmCSConnect::prepareProducts()
{
 auto_ptr<TIBQuery> qry = AppController::getSmartNewQuery();
 auto_ptr<TIBQuery> qryInsert = AppController::getSmartNewQuery();

 qry->SQL->Text = "SELECT * FROM PRODUCT WHERE PRODUCT_ID IN (1001,1002,1003)";
 qry->Open();
 if(qry->RecordCount == 3)
  return;

 qryInsert->SQL->Add("INSERT INTO PRODUCT(PRODUCT_ID, DESCRIPTION_SHORT,VATCAT_ID,CAT_ID, SELL_PRICE, PRICE_WVAT, METRIC_ID) \
					VALUES(:PRODUCT_ID, :DESCRIPTION_SHORT, 2,2, 0.813, 1,1)");

 qry->SQL->Text = "SELECT * FROM PRODUCT WHERE PRODUCT_ID = :PRODUCT_ID";
 qry->ParamByName("PRODUCT_ID")->AsInteger = 1001;
 qry->Open();

 if (qry->RecordCount == 0)
 {
  qryInsert->ParamByName("PRODUCT_ID")->AsInteger = 1001;
  qryInsert->ParamByName("DESCRIPTION_SHORT")->AsString = "Υπηρεσίες Web Hosting";
  qryInsert->ExecSQL();
 }
 qry->Close();

 qry->ParamByName("PRODUCT_ID")->AsInteger = 1002;
 qry->Open();
 if (qry->RecordCount == 0)
 {
  qryInsert->ParamByName("PRODUCT_ID")->AsInteger = 1002;
  qryInsert->ParamByName("DESCRIPTION_SHORT")->AsString = "Υπηρεσίες Domain Names";
  qryInsert->ExecSQL();
 }
 qry->Close();

 qry->ParamByName("PRODUCT_ID")->AsInteger = 1003;
 qry->Open();
 if (qry->RecordCount == 0)
 {
  qryInsert->ParamByName("PRODUCT_ID")->AsInteger = 1003;
  qryInsert->ParamByName("DESCRIPTION_SHORT")->AsString = "Υπηρεσίες Data Hosting";
  qryInsert->ExecSQL();
 }
 qry->Close();

 transaction->CommitRetaining();
}

//Depricated
void TFrmCSConnect::setCreatedInvId(int _invoiceId)//Depricated
{ //Depricated
 TUniQuery *query = new TUniQuery(this);

 query->Connection = sqlConnection;

 query->SQL->Add("UPDATE tblinvoices set invoiced = :GenInvId where id = :invid");
 query->ParamByName("invid")->AsInteger = QueryInvoices->FieldByName("id")->AsInteger;
 query->ParamByName("GenInvId")->AsInteger = _invoiceId;
 query->ExecSQL();

 QueryInvoices->Refresh();

 if(checkEmail->Checked == true)
 {
//  ShowMessage("Will send mail1");
  mailInvoice(_invoiceId);
 }
 delete query;
}

void TFrmCSConnect::mailInvoice(int _invoiceId)
{
// ShowMessage("INVOICE:"+AnsiString(_invoiceId));
 TFrmMailInvoices *frmMailInvoice = new TFrmMailInvoices(Owner, _invoiceId, true);
}

void TFrmCSConnect::setAllCreatedInvsId(int _invoiceId)
{
 AnsiString ids;
 ids = QueryInvoices->FieldByName("id")->AsInteger;
 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  ids += ", ";
  ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
  Application->ProcessMessages();
  ids += QueryInvoices->FieldByName("id")->AsInteger;
 }

 TUniQuery *query = new TUniQuery(this);

 query->Connection = sqlConnection;

 query->SQL->Add("UPDATE tblinvoices set invoiced = :GenInvId where id in (" + ids + ")");
 query->ParamByName("GenInvId")->AsInteger = _invoiceId;
 query->ExecSQL();

 if(checkEmail->Checked == true)
  mailInvoice(_invoiceId);

 delete query;
}

void __fastcall TFrmCSConnect::ToolRefresh1Click(TObject *Sender)
{
 refreshDataset(QueryInvoices);
}
// ---------------------------------------------------------------------------

int TFrmCSConnect::returnChecked()
{
 if (radioUnInvoiced->Checked)
  return (0);
 else if (radioSuppressed->Checked)
  return (-1);
}

void __fastcall TFrmCSConnect::Button2Click(TObject *Sender)
{
 AnsiString ids;
 ids = QueryInvoices->FieldByName("id")->AsInteger;
 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  ids += ", ";
  ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
  Application->ProcessMessages();
  ids += QueryInvoices->FieldByName("id")->AsInteger;
 }

 TUniQuery *query = new TUniQuery(this);

 query->Connection = sqlConnection;

 query->SQL->Add("UPDATE tblinvoices set invoiced = 0 where id IN ( " + ids + ");");
 query->ExecSQL();

// QueryInvoices->Refresh();
 ToolRefresh1->Click();
	delete query;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::radioUnInvoicedClick(TObject *Sender)
{
 QueryInvoices->Close();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "where mi.status = 'Paid'  and invoiced = 0";
 QueryInvoices->Open();

 cxCalcEdit1->Enabled = false;
 cmdSearchInvoice->Enabled = false;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::radioSuppressedClick(TObject *Sender)
{
 QueryInvoices->Close();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "where mi.status = 'Paid'  and invoiced = -1";
 QueryInvoices->Open();

 cxCalcEdit1->Enabled = false;
 cmdSearchInvoice->Enabled = false;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::JvRadioButton1Click(TObject *Sender)
{
 QueryInvoices->Close();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "where mi.status = 'Paid'  and invoiced > 1";
 QueryInvoices->Open();

 cxCalcEdit1->Enabled = false;
 cmdSearchInvoice->Enabled = false;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::JvRadioButton2Click(TObject *Sender)
{
 QueryInvoices->Close();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "where mi.id = :INVOICEID";
 cxCalcEdit1->Enabled = true;
 cmdSearchInvoice->Enabled = true;
// ShowMessage(QueryInvoices->SQL->Text);
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::cmdSearchInvoiceClick(TObject *Sender)
{
 //ShowMessage(QueryInvoices->SQL->Text);
 QueryInvoices->Close();
 QueryInvoices->ParamByName("INVOICEID")->AsInteger = cxCalcEdit1->Value;
 QueryInvoices->Open();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::btnShowCustClick(TObject *Sender)
{
 TFrmShowCustomers *frmShowCustomers = new TFrmShowCustomers(Owner);

 frmShowCustomers->locateCust(QueryLocalCustomer->FieldByName("CUST_ID")->AsInteger);
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::Button4Click(TObject *Sender)
{
 QueryInvoices->Filter = "userid = " + AnsiString(QueryInvoices->FieldByName("userid")->AsInteger);
 QueryInvoices->Filtered = true;
 ViewCSInvoice->DataController->SelectAll();

	try
	{
		cmdInvoice->Click();
	}catch(...){}

	QueryInvoices->Filtered = false;
 ToolRefresh1->Click();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::QueryLocalCustomerAfterOpen(TDataSet *DataSet)
{
 if (QueryLocalCustomer->RecordCount == 0)
 {
  btnShowCust->Enabled = false;
  QueryAcceptedCustomer->Close();
 }
 else
 {
  btnShowCust->Enabled = true;
  QueryAcceptedCustomer->Open();
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::FormClose(TObject *Sender, TCloseAction &Action)
{
 RegAccess *reg = new RegAccess(this);
 reg->saveCustomBool("checkEmail", checkEmail->Checked);

 delete reg;
}
//---------------------------------------------------------------------------


void __fastcall TFrmCSConnect::EditFilterPropertiesChange(TObject *Sender)
{
 if(EditFilter->Text == "")
 {
  QueryInvoices->Filtered = false;
  return;
 }

 TFilterOptions opts;

 opts << foCaseInsensitive;
 QueryInvoices->FilterOptions = opts;
 QueryInvoices->Filter = "(companyname = '%" + EditFilter->Text+ "%') OR (firstname = '%"+EditFilter->Text+"%') OR (lastname = '%"+EditFilter->Text+"%')" ;
 QueryInvoices->Filtered = true;
}
//---------------------------------------------------------------------------


void __fastcall TFrmCSConnect::cxCalcEdit1KeyDown(TObject *Sender, WORD &Key, TShiftState Shift)
{
 if(Key == 13)
  cmdSearchInvoice->Click();
}
//---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::FormShow(TObject *Sender)
{
 formVisible = true;
}
//---------------------------------------------------------------------------



void __fastcall TFrmCSConnect::JvRadioButton3Click(TObject *Sender)
{
 QueryInvoices->Close();
 QueryInvoices->SQL->Strings[QueryInvoices->SQL->Count - 2] = "where mi.status = 'Paid'  and invoiced = -1000";
 QueryInvoices->Open();

 cxCalcEdit1->Enabled = false;
	cmdSearchInvoice->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmCSConnect::Button1Click(TObject *Sender)
{
AnsiString ids;
// ids = QueryInvoices->FieldByName("id")->AsInteger;

 for (int i = 0; i < ViewCSInvoice->DataController->GetSelectedCount(); i++)
 {
  if(i > 0)
   ids += ", ";
		ViewCSInvoice->DataController->FocusedRowIndex = ViewCSInvoice->DataController->GetSelectedRowIndex(i);
		Application->ProcessMessages();
		ids += QueryInvoices->FieldByName("id")->AsInteger;
	}

	if(ids == "")
		return;

	auto_ptr<TUniQuery> query(new TUniQuery(this));

	query->Connection = sqlConnection;


	query->SQL->Add("UPDATE tblinvoices set invoiced = -333 where id IN ( " + ids + ");");
//  ShowMessage(query->SQL->Text);
	query->ExecSQL();

 ToolRefresh1->Click();
}
//---------------------------------------------------------------------------

