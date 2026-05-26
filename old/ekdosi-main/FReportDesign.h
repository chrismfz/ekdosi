//---------------------------------------------------------------------------

#ifndef FReportDesignH
#define FReportDesignH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "frxDesgn.hpp"
#include "frxClass.hpp"
#include "frxIBXComponents.hpp"
#include "frxDBSet.hpp"
#include <DB.hpp>
#include "JvDotNetControls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include <ExtCtrls.hpp>
#include "frxDCtrl.hpp"
#include "frxBarcode.hpp"

#include "CNewSpecialForm.h"
//---------------------------------------------------------------------------
class TFrmReportDesign : public NewSpecialForm
{
__published:	// IDE-managed Components
	TfrxReport *Report;
	TfrxBarCodeObject *frxBarCodeObject1;
	TfrxIBXComponents *frxIBXComponents1;

private:	// User declarations

public:		// User declarations
	__fastcall TFrmReportDesign(TComponent* Owner);
	__fastcall TFrmReportDesign(TComponent* Owner, AnsiString filename);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmReportDesign *FrmReportDesign;
//---------------------------------------------------------------------------
#endif
