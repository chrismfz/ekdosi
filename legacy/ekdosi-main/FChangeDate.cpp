//---------------------------------------------------------------------------
#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FChangeDate.h"

//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvDotNetControls"
#pragma link "JvExMask"
#pragma link "JvToolEdit"
#pragma resource "*.dfm"
TFrmChangeDate *FrmChangeDate;
//---------------------------------------------------------------------------
__fastcall TFrmChangeDate::TFrmChangeDate(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 if(AnsiString(Owner->ClassName())=="TFrmMain")
 {
  frmOwner = (TFrmMain *)Owner;
 }

 buttonRollback->Visible = false;
}
//---------------------------------------------------------------------------
void __fastcall TFrmChangeDate::cmdAuthenticationClick(TObject *Sender)
{
 frmOwner->setDate(editDate->Date);
 Close();	
}
//---------------------------------------------------------------------------
