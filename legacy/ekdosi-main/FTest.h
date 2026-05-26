//---------------------------------------------------------------------------

#ifndef FTestH
#define FTestH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
//---------------------------------------------------------------------------
class TFrmTest : public TForm
{
__published:	// IDE-managed Components
private:	// User declarations
	HWND FMDIClientHandle;
	HWND __fastcall GetMDIClientHandle();
	void __fastcall CreateWindowHandle(const TCreateParams &Params);
	void __fastcall DestroyWindowHandle();
public:		// User declarations
	__fastcall TFrmTest(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmTest *FrmTest;
//---------------------------------------------------------------------------
#endif
