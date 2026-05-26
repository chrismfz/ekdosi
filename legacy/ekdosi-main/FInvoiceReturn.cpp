//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FInvoiceReturn.h"
#include "FPrint.h"

//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma resource "*.dfm"
TFrmInvoiceReturn *FrmInvoiceReturn;
//---------------------------------------------------------------------------
__fastcall TFrmInvoiceReturn::TFrmInvoiceReturn(TComponent* Owner)
	: NewSpecialForm(Owner)
{
StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();
// transaction->DefaultAction =  TARollback; //Rollback remaining invoice lines

 //set main dataset
 dataset = DatasetProductQty;
 dataset->Database = database;
 dataset->Transaction = transaction;

 editDate->Date = runningDate;

// loadInvoice();

}
//---------------------------------------------------------------------------

int TFrmInvoiceReturn::findCumInvoiceDate(TDate _date)
{
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;


 //Ίσως αν πείραζα αυτή την γραμμή να μπορούσα να αφαιρέσω τον έλεγχο περι ημερομηνίας
 query->SQL->Add("SELECT INVOICE_ID,INVTYPE,CONV_INVOICE_ID FROM INVOICE ORDER BY INVOICE_ID DESC");
 query->Active = true;

 query->Last();
 int recordNumber = query->RecordCount;
 query->First();

 while((query->FieldByName("INVTYPE")->AsString != "ΣΔΑΠ" || !query->FieldByName("CONV_INVOICE_ID")->IsNull )  && query->RecNo < recordNumber)
 {
  if(query->FieldByName("INVTYPE")->AsString == "ΣΔΕΠ")
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


void TFrmInvoiceReturn::createReturnInvoice()
{
 TIBQuery *query = new TIBQuery(this);

 query->Database = database;
 query->Transaction = transaction;

 query->SQL->Text = "SELECT INVOICE_ID FROM CREATE_RETURN_INVOICE(:SDEP_INV_ID, :INVDATE, :INVTIME);";
 query->ParamByName("SDEP_INV_ID")->AsInteger = cumInvoiceId;
 query->ParamByName("INVDATE")->AsDate = editDate->Date;
 query->ParamByName("INVTIME")->AsTime = editTime->Time;
 query->Open();

 dataset->ParamByName("INVOICE_ID")->AsInteger = query->FieldByName("INVOICE_ID")->AsInteger;
 dataset->Open();

 delete query;
}

void __fastcall TFrmInvoiceReturn::JvDotNetButton2Click(TObject *Sender)
{
 transaction->Rollback();
 Close();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmInvoiceReturn::cmdAcceptClick(TObject *Sender)
{
 if(showMessage("Εκτύπωση; ",ApplicationName, MB_YESNO) == 6)
 {//print
  transaction->CommitRetaining();
  TIBTransaction *trns = new TIBTransaction(this);
  trns->DefaultDatabase = database;
 TFrmPrint *frmPrint = new TFrmPrint(Owner, trns,
			getReportFilename(),
			"INVOICE_ID",
			dataset->FieldByName("INVOICE_ID")->AsInteger, true);

	}
 Close();
}
//---------------------------------------------------------------------------

AnsiString TFrmInvoiceReturn::getReportFilename ()
{
TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Text = "SELECT FRM_FILENAME FROM INVTYPE WHERE INVTYPE_ID = 'ΣΔΕΠ'";
 query->Open();
 AnsiString filename = query->FieldByName("FRM_FILENAME")->AsString;
 delete query;
 return(filename);
}
void __fastcall TFrmInvoiceReturn::JvDotNetButton1Click(TObject *Sender)
{

//See if there is a Cum Invoice
 cumInvoiceId = findCumInvoiceDate(editDate->Date);

 if(cumInvoiceId == 0)
 {
  showMessage("Δεν έχει εκδοθεί Συγκεντρωτικό δελτίο αποστολής!",ApplicationName, MB_ICONERROR);
  dataset->Close();
  return;
 }

 //create a new return invoice
 if(showMessage("Θέλετε να εκδόσετε νεό Συγκεντρωτικό ΔΕΠ;",ApplicationName, MB_YESNO)==6)
 {
  createReturnInvoice();
 }

 JvPanel2->Show();
 JvPanel3->Show();
}
//---------------------------------------------------------------------------

