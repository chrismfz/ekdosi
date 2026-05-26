//---------------------------------------------------------------------------

#ifndef FSelectDateH
#define FSelectDateH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include <ComCtrls.hpp>
#include "JvDotNetControls.hpp"

//---------------------------------------------------------------------------
#include "CNewSpecialForm.h"

typedef void (__closure *ptrSetDate)(TDate);

class TFrmSelectDate : public NewSpecialForm
{
__published:	// IDE-managed Components
	TMonthCalendar *Calendar;
	TJvDotNetButton *buttonOk;
	void __fastcall buttonOkClick(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
private:	// User declarations
	ptrSetDate ptrSetDateForm;
	NewSpecialForm *motherForm;
public:		// User declarations
	__fastcall TFrmSelectDate(TComponent* Owner,NewSpecialForm *mother, AnsiString caption, ptrSetDate ptr);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmSelectDate *FrmSelectDate;
//---------------------------------------------------------------------------
#endif
