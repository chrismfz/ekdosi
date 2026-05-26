//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FSelectDate.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)

#pragma link "JvDotNetControls"
#pragma resource "*.dfm"
TFrmSelectDate *FrmSelectDate;
//---------------------------------------------------------------------------
__fastcall TFrmSelectDate::TFrmSelectDate(TComponent* Owner,NewSpecialForm *mother, AnsiString caption, ptrSetDate ptr)
	: NewSpecialForm(Owner)
{
 disableCancelButton();
 motherForm = mother;
 ptrSetDateForm = ptr;
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectDate::buttonOkClick(TObject *Sender)
{
(ptrSetDateForm)(Calendar->Date);
 Close();
}
//---------------------------------------------------------------------------


void __fastcall TFrmSelectDate::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 motherForm->Enabled = true;	
}
//---------------------------------------------------------------------------

