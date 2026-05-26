//---------------------------------------------------------------------------

#ifndef FSplashH
#define FSplashH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include <ExtCtrls.hpp>
#include "JvCheckedMaskEdit.hpp"
#include "JvDatePickerEdit.hpp"
#include "JvExMask.hpp"
#include "JvMaskEdit.hpp"
#include "JvToolEdit.hpp"
#include <Mask.hpp>
#include "JvTimer.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLabel.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
//---------------------------------------------------------------------------
class TFrmSplash : public TForm
{
__published:	// IDE-managed Components
	TJvPanel *JvPanel1;
	TLabel *Label1;
	TLabel *LabelVersion;
	TLabel *Label3;
	TLabel *Label4;
	TLabel *lblWait;
	TJvDotNetEdit *editUsername;
	TJvDotNetEdit *editPassword;
	TJvDotNetButton *cmdAuthentication;
	TLabel *Label2;
	TJvDateEdit *editDate;
	TJvTimer *JvTimer1;
	TcxLabel *labelCounter;
	void __fastcall cmdAuthenticationClick(TObject *Sender);
	void __fastcall editUsernameKeyPress(TObject *Sender, char &Key);
	void __fastcall editPasswordKeyPress(TObject *Sender, char &Key);
	void __fastcall editDateKeyPress(TObject *Sender, char &Key);
	void __fastcall JvTimer1Timer(TObject *Sender);
private:	// User declarations
	 AnsiString getVersion();
     int counter;
public:		// User declarations
	__fastcall TFrmSplash(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmSplash *FrmSplash;
//---------------------------------------------------------------------------
#endif
