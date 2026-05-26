//---------------------------------------------------------------------------

#ifndef FAboutOptimumH
#define FAboutOptimumH
//---------------------------------------------------------------------------
#include <System.Classes.hpp>
#include <Vcl.Controls.hpp>
#include <Vcl.StdCtrls.hpp>
#include <Vcl.Forms.hpp>
#include "dxGDIPlusClasses.hpp"
#include <Vcl.ExtCtrls.hpp>
#include "cxButtons.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include <Vcl.Menus.hpp>
//---------------------------------------------------------------------------
class TFrmAboutOptimum : public TForm
{
__published:	// IDE-managed Components
	TImage *Image1;
	TLabel *Label1;
	TLabel *Label2;
	TLabel *Label3;
	TLabel *Label4;
	TLabel *Label5;
	TLabel *Label6;
	TLabel *Label7;
	TLabel *Label8;
	TLabel *Label9;
	TLabel *Label10;
	TLabel *Label11;
	TcxButton *cxButton1;
	void __fastcall cxButton1Click(TObject *Sender);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmAboutOptimum(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAboutOptimum *FrmAboutOptimum;
//---------------------------------------------------------------------------
#endif
