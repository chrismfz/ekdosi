//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop

#include "FAboutOptimum.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "dxGDIPlusClasses"
#pragma link "cxButtons"
#pragma link "cxGraphics"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma resource "*.dfm"
TFrmAboutOptimum *FrmAboutOptimum;
//---------------------------------------------------------------------------
__fastcall TFrmAboutOptimum::TFrmAboutOptimum(TComponent* Owner)
	: TForm(Owner)
{
}
//---------------------------------------------------------------------------
void __fastcall TFrmAboutOptimum::cxButton1Click(TObject *Sender)
{
 Close();
}
//---------------------------------------------------------------------------
