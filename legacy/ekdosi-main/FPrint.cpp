//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FPrint.h"
#include "RegistryAccess.h"
#include "FSelectCustomer.h"
#include "FSelectProduct.h"
#include "FSelectDate.h"
#include "CMyData.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmPrint *FrmPrint;
//---------------------------------------------------------------------------
__fastcall TFrmPrint::TFrmPrint(TComponent* Owner,TIBTransaction *trans, AnsiString _filename,AnsiString _parameter, int _invoiceId, bool _printInvoice, bool _checkMyData)
	: NewSpecialForm(Owner)
{
 RegAccess *registry = new RegAccess(this);
 doNotDeleteTransaction = true;
	invoicePrint = false;
	invoiceId = _invoiceId;
	checkMyDataOnInvoice = _checkMyData;
	pdfExport = false;
 frxIBXComponents1->DefaultDatabase = database;

	// delete transaction;
// transaction = trans;
 this->Visible = false;
// frxIBXComponents1->DefaultDatabase->DefaultTransaction = transaction;

 filename = _filename;
 if(_parameter=="MULTIPARAM")  //if it's a report and not invoice
  return;

 //if it reaches here then it's INVOICE 
 if(!FileExists(filename))
	{
  filename = filename.SubString(4,filename.Length());
 }

 Report->LoadFromFile(filename,true);
 Report->Variables->Items[Report->Variables->IndexOf(_parameter)]->Value = invoiceId;
 if(_printInvoice)
  Report->PrepareReport(true);
 Report->PrintOptions->Printer = getPrinterName();

 Report->FileName = getInvCode(invoiceId);

	if(_printInvoice)
	{
		checkPdfExport();
		if(!pdfExport)
			checkBullZipPdf();
	}

 Report->DotMatrixReport = false;
 
 if(isDotMatrix())
 {
  Report->DotMatrixReport = true;
  DotMatrixReport->FileName = getInvCode(invoiceId);
 }


 if(_printInvoice)
 {
  invoicePrint = true;
  Report->PrintOptions->ShowDialog = false;
  TimerPrint->Enabled = true;
 }
 else
	{
		if(!checkMyData())
			showMessage("Invoice will not be printed due to missing myDATA MARK!", MB_ICONERROR);
		else
   Report->ShowReport(true);
  Close();
 }


 delete registry;
}
//---------------------------------------------------------------------------

__fastcall TFrmPrint::TFrmPrint(TComponent* Owner,TIBTransaction *trans, int reportId)
	: NewSpecialForm(Owner)
{
 RegAccess *registry = new RegAccess(this);
 doNotDeleteTransaction = true;
 frxIBXComponents1->DefaultDatabase = database;
 delete transaction;
 transaction = trans;
 this->Visible = false;
// frxIBXComponents1->DefaultDatabase->DefaultTransaction = transaction;

 TIBQuery *queryReport = new TIBQuery(this);
 queryReport->Database = database;
 queryReport->Transaction = transaction;
 queryReport->SQL->Add("SELECT * FROM REPORTS WHERE REPORT_ID = :REPORT_ID");
 queryReport->ParamByName("REPORT_ID")->AsInteger = reportId;
 queryReport->Open();
 
 TIBQuery *queryVars = new TIBQuery(this);
 queryVars->Database = database;
 queryVars->Transaction = transaction;
 queryVars->SQL->Add("SELECT *  \
		FROM REPORT_INPUT_DATA   \
		WHERE REPORT_ID = :REPORT_ID  \
		ORDER BY \
		INPUTDATA_ID DESC ");

 queryVars->ParamByName("REPORT_ID")->AsInteger = reportId;
 queryVars->Open();

 Report->LoadFromFile(queryReport->FieldByName("FILENAME")->AsString);


 int i=0;
 while(i < queryVars->RecordCount)
 {
  if(queryVars->FieldByName("VAL")->AsString == "[PRODUCT_ID]")
  {
   this->Enabled = false;
   tmpId = -1;
   TFrmSelectProduct *frmSelectProduct = new TFrmSelectProduct(Owner,this,setCustomId, "" , "");
   while(!this->Enabled)
   {
	Sleep(70);
	Application->ProcessMessages();
   }
   if(tmpId>0)
    Report->Variables->Items[Report->Variables->IndexOf(queryVars->FieldByName("VARIABLE_NAME")->AsString)]->Value = tmpId;
  }
  else if(queryVars->FieldByName("VAL")->AsString == "[CUSTOMER_ID]" )
  {
   tmpId = -1;
   this->Enabled = false;
   TFrmSelectCustomer *frmSelectProduct = new TFrmSelectCustomer(Owner,this,setCustomId, "" , "");
   while(!this->Enabled)
   {
	Sleep(70);
	Application->ProcessMessages();
   }
   if(tmpId> 0)
	Report->Variables->Items[Report->Variables->IndexOf(queryVars->FieldByName("VARIABLE_NAME")->AsString)]->Value = tmpId;
  }//else if
  else if(queryVars->FieldByName("VAL")->AsString == "[DATE]" )
		{
   tmpId = -1;
   this->Enabled = false;
   TFrmSelectDate *frmSelectDate = new TFrmSelectDate(Owner,this,"",setCustomDate);
   while(!this->Enabled)
   {
	Sleep(70);
	Application->ProcessMessages();
   }
   if(tmpId> 0)
	Report->Variables->Items[Report->Variables->IndexOf(queryVars->FieldByName("VARIABLE_NAME")->AsString)]->Value = AnsiString("'")+tmpId+AnsiString("'");
  }//else if
  else
  {
   Report->Variables->Items[Report->Variables->IndexOf(queryVars->FieldByName("VARIABLE_NAME")->AsString)]->Value = queryVars->FieldByName("VAL")->AsVariant;
  }//SOMEWHERE HERE I HAVE TO ATTACH THE CODE FOR SELECTORS
  
  i++;
  queryVars->Next();

  if(tmpId < 1)
  {
   delete queryReport, queryVars;
   Close();
   return;
  }
 }

// Report->PrepareReport(true);
 Report->ShowReport(true);

 delete queryReport, queryVars;

 Close();
}

void TFrmPrint::setCustomId(int _customId)
{
 tmpId = _customId;
}

void TFrmPrint::setCustomDate(TDate _date)
{
 tmpId = _date;
}

AnsiString TFrmPrint::getPrinterName()
{
 RegAccess *reg = new RegAccess(this);

 AnsiString str = reg->getAppParameterString("SelectedInvoicePrinterString");
 delete reg;

 return(str);

}

AnsiString TFrmPrint::getInvCode(int invoiceId)
{
 TIBQuery *query = new TIBQuery(this);

 query->Database = database;
 query->Transaction = transaction;

 query->SQL->Text = "SELECT * FROM INVOICE WHERE INVOICE_ID = :INVOICE_ID";
 query->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
 query->Open();

 AnsiString id;

 id = query->FieldByName("INVCODE")->AsString;
 delete query;
 return(id);
}

void TFrmPrint::checkPdfExport()
{
	pdfExport = false;

	if(getConfLong("PdfExport") == 1)
	{
		pdfExport = true;
		pdfExportDir = getConfString("PdfExportDir");
		PdfExport->DefaultPath = pdfExportDir;
		PdfExport->FileName = Report->FileName+".pdf";
		PdfExport->ShowDialog = false;
		PdfExport->OverwritePrompt = false;
	}
}

void TFrmPrint::checkBullZipPdf()
{
	RegAccess *registry = new RegAccess(this);

 if(getConfLong("BullZipEnabled"))///registry->getAppParameterInt("BullZipEnabled"))
 {
  AnsiString saveDirectory = getConfString("PdfSaveDir");//registry->getAppParameterString("PdfSaveDir");

  AnsiString filename = " \""+getConfString("PdfInstallDir")+"\\API\\EXE\\config.exe\" ";

  AnsiString runCommand = "C: & cd \ & "+filename + "/S OUTPUT \"" + saveDirectory+"\\" +Report->FileName+".pdf\" &" +
				 filename +"/S showsaveas never & "+ filename+"/S showsettings never &" +
				 filename+"/S showpdf no";


// ShowMessage(runCommand);
  system(runCommand.c_str());
 }

	delete registry;

}

bool TFrmPrint::isDotMatrix()
{
 RegAccess *reg = new RegAccess(this);

 bool str = reg->getAppParameterInt("DotMatrixPrinter");
 delete reg;

 return(str);
}

void TFrmPrint::addParameter(AnsiString _paramName, Variant _paramValue)
{
 paramNames.push_back(_paramName);
 paramValues.push_back(_paramValue);
}

void TFrmPrint::execute()
{
 if(paramNames.size() != paramValues.size())
  throw ("Η τιμές παραμέτρων δεν είναι ίσες με τα ονομάτα των παραμέτρων!");

 if(!FileExists(filename))
 {
  filename = filename.SubString(4,filename.Length());
  if(!FileExists(filename))
   throw("Το αρχείο report δεν υπάρχει!");
 }

 vector<AnsiString>::iterator paramName;
 vector<Variant>::iterator paramValue;
 paramName = paramNames.begin();
 paramValue = paramValues.begin();


 Report->LoadFromFile(filename,true);
 while(paramName != paramNames.end())
 {
  Report->Variables->Items[Report->Variables->IndexOf(*paramName)]->Value = *paramValue;
  paramName++;
  paramValue++;
 }

 Report->PrepareReport(true);
 Report->ShowReport(true);

 while(Report->Designer != NULL)
 {
  Application->ProcessMessages();
  Sleep(70);
 }
 Close();
}

void __fastcall TFrmPrint::ReportAfterPrintReport(TObject *Sender)
{
// Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmPrint::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 delete transaction;
}
//---------------------------------------------------------------------------

void __fastcall TFrmPrint::TimerPrintTimer(TObject *Sender)
{
 TimerPrint->Enabled = false;

	if(!checkMyData())
	{
		showMessage("Invoice will not be printed due to missing myDATA MARK!", MB_ICONERROR);
		Close();
  return;
	}

 try
	{
		if(pdfExport)
		{
		 PdfExport->Subject = "Invoice";
			Report->Export(PdfExport);
		}
		else
 		Report->Print();
 }catch(Exception &e){}

 if(invoicePrint)
 {
  Close();
  return;
 }

 try
 {
  while(Report->Designer != NULL)
   {
				Application->ProcessMessages();
				Sleep(130);
   }
	}catch(...)
 {

	}

 Close();
}
//---------------------------------------------------------------------------

bool TFrmPrint::checkMyData()
{
	if(checkMyDataOnInvoice == false)
		return(true);

	std::auto_ptr<MyData> myData(new MyData());
	bool invoiceValid = myData->checkMarkExistsForInvoice(invoiceId);
	return(invoiceValid);
}

