//---------------------------------------------------------------------------

#ifndef FChangeDateH
#define FChangeDateH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDotNetControls.hpp"
#include "JvExMask.hpp"
#include "JvToolEdit.hpp"
#include <Mask.hpp>

#include "CNewSpecialForm.h"
//---------------------------------------------------------------------------
class TFrmChangeDate : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvDateEdit *editDate;
	TLabel *Label2;
	TJvDotNetButton *cmdAuthentication;
	void __fastcall cmdAuthenticationClick(TObject *Sender);
private:	// User declarations
	TFrmMain *frmOwner;
public:		// User declarations
	__fastcall TFrmChangeDate(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmChangeDate *FrmChangeDate;
//---------------------------------------------------------------------------
#endif
