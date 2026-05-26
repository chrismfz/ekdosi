//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FReportDesign.h"
#include "FMain.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "frxDesgn"
#pragma link "frxClass"
#pragma link "frxIBXComponents"
#pragma link "frxDBSet"
#pragma link "JvDotNetControls"
#pragma link "JvExExtCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "frxDCtrl"
#pragma link "frxBarcode"
#pragma resource "*.dfm"
TFrmReportDesign *FrmReportDesign;
//---------------------------------------------------------------------------
__fastcall TFrmReportDesign::TFrmReportDesign(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 frxIBXComponents1->DefaultDatabase = database;
 this->Visible = false;
 doNotDeleteTransaction = true;

 Report->DesignReport(false,false);
 Report->Designer->SetFocus();
  while(Report->Designer != NULL)
  {
   Application->ProcessMessages();
   Sleep(70);
  }
 Close();
}
//---------------------------------------------------------------------------

__fastcall TFrmReportDesign::TFrmReportDesign(TComponent* Owner, AnsiString filename)
    : NewSpecialForm(Owner)
{
 frxIBXComponents1->DefaultDatabase = database;
 this->Visible = false;
 doNotDeleteTransaction = true;

 if(!FileExists(filename))
 {
  filename = filename.SubString(4,filename.Length());
 }
 if(!FileExists(filename))
 {
  showMessage("Το αρχείο δεν υπάρχει!",ApplicationName, MB_ICONERROR);
  Close();
 }

 Report->LoadFromFile(filename);
 Report->DesignReport(false,false);
 Report->Designer->SetFocus();
  while(Report->Designer != NULL)
  {
   Application->ProcessMessages();
   Sleep(70);
  }
 Close();
}





