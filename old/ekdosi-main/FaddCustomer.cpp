//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FaddCustomer.h"
#include "FMain.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmAddCustomer *FrmAddCustomer;
//---------------------------------------------------------------------------
__fastcall TFrmAddCustomer::TFrmAddCustomer(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 dataset = DatasetCustomer;
 datasetNew = 0;

 QueryPaymentMethod->Open();
 QueryOccupation->Open();
 QueryTaxOffice->Open();
 QueryCountry->Open();
 QueryCity->Open();


	dataset->Active = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddCustomer::FormShow(TObject *Sender)
{
	DatasetCustomer->Insert();
	ComboType->ItemIndex = 0;
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddCustomer::JvDotNetButton1Click(TObject *Sender)
{
 if(!checkDeps())
  return;

 DatasetCustomer->FieldByName("CUST_ID")->AsInteger = 0;

 if(checkVatExists(DatasetCustomer->FieldByName("AFM")->AsString))
 {
  int answer = showMessage("Το ΑΦΜ υπάρχει! Είστε σίγουρος ότι θέλετε να συνεχίσετε;",ApplicationName, MB_ICONINFORMATION | MB_YESNO);
  if(answer != 6)
  {
   editVatNo->SetFocus();
   return;
  }
 }

 try
 {
  DatasetCustomer->Post();
 }
 catch (EIBInterBaseError &e)
 {
  return;
 }

 DatasetCustomer->Insert();
 editName->SetFocus();
}
//---------------------------------------------------------------------------

bool TFrmAddCustomer::checkVatExists(AnsiString _vatNumber)
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

bool TFrmAddCustomer::checkDeps()
{
 if(editName->Text.Trim().Length() == 0 )
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά πεδία.",ApplicationName, MB_ICONERROR);
  return(false);
 }
 return(true);

}
void __fastcall TFrmAddCustomer::JvDotNetButton2Click(TObject *Sender)
{
 DatasetCustomer->Cancel();
 Close();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddCustomer::editPercentExit(TObject *Sender)
{
 if(editPercent->Text.Length() > 0 && (editPercent->Text.ToInt() <0 || editPercent->Text.ToInt() >100) )
 {
  showMessage("Παρακαλώ εισάγεται ποσοστό επι τοις εκατό.",ApplicationName, MB_ICONERROR);
  editPercent->SetFocus();
  editPercent->SelectAll();
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddCustomer::editVatNoChange(TObject *Sender)
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

void __fastcall TFrmAddCustomer::editVatNoExit(TObject *Sender)
{
// if(editVatNo->Text.Length() < 9 || checkVatCode(editVatNo->Text) == false)
//  showMessage("Το ΑΦΜ που πληκτρολογήσατε δεν είναι έγκυρο!",ApplicationName,MB_ICONERROR);

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddCustomer::DatasetCustomerAfterInsert(TDataSet *DataSet)
{
 datasetNew = DatasetCustomer;
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddCustomer::ComboTypePropertiesChange(TObject *Sender)
{
	if(ComboType->ItemIndex == 1)
		DatasetCustomer->FieldByName("TYPE")->AsString = "INDIVIDUAL";
	else
  DatasetCustomer->FieldByName("TYPE")->AsString = "BUSINESS";
}
//---------------------------------------------------------------------------

