//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FSplash.h"
#include "FMain.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvDotNetControls"
#pragma link "JvEdit"
#pragma link "JvExExtCtrls"
#pragma link "JvExStdCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "JvCheckedMaskEdit"
#pragma link "JvDatePickerEdit"
#pragma link "JvExMask"
#pragma link "JvMaskEdit"
#pragma link "JvToolEdit"
#pragma link "JvTimer"
#pragma link "cxContainer"
#pragma link "cxControls"
#pragma link "cxEdit"
#pragma link "cxGraphics"
#pragma link "cxLabel"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma resource "*.dfm"
TFrmSplash *FrmSplash;
//---------------------------------------------------------------------------
__fastcall TFrmSplash::TFrmSplash(TComponent* Owner)
	: TForm(Owner)
{
 LabelVersion->Caption = "Version "+getVersion();
 counter = 2;
 labelCounter->Caption = counter;
}
//---------------------------------------------------------------------------

AnsiString TFrmSplash::getVersion()
{
DWORD infoSZ = GetFileVersionInfoSize(UnicodeString(Application->ExeName.c_str()).c_str() ,0);
  if (infoSZ)
  {
    void *lpData = new BYTE[infoSZ];
    try{
      if ( GetFileVersionInfo(UnicodeString(Application->ExeName.c_str()).c_str(),0,infoSZ,lpData ) )
      {
        UINT puLen;
        VS_FIXEDFILEINFO *fInfo;
		if (VerQueryValue( lpData, L"\\",(void**)&fInfo,&puLen))
        {
          // Version Info
          DWORD major = HIWORD(fInfo->dwFileVersionMS);
          DWORD minor = LOWORD(fInfo->dwFileVersionMS);
          DWORD release =  HIWORD(fInfo->dwFileVersionLS);
          DWORD build =  LOWORD(fInfo->dwFileVersionLS);
          AnsiString txt;
          txt.sprintf("%d.%d.%d.%d ",major,minor,release,build);
          return(txt);
        }
      }
      delete[] lpData;
    }
    catch(...)
    {
      delete[] lpData;   
    }
  }
 /* else
    ShowMessage("No Version Info available...");*/
  return("0");
}
void __fastcall TFrmSplash::cmdAuthenticationClick(TObject *Sender)
{
 TFrmMain *frmMain = (TFrmMain *)Application->MainForm;
 frmMain->setDate(editDate->Date);
 Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmSplash::editUsernameKeyPress(TObject *Sender, char &Key)
{
 if(Key == '\r')
 {
  editPassword->SetFocus();
  Key = 0;
 }
}
//---------------------------------------------------------------------------


void __fastcall TFrmSplash::editPasswordKeyPress(TObject *Sender, char &Key)
{
 if(Key == '\r')
 {
  editDate->SetFocus();
  Key = 0;
 }
}
//---------------------------------------------------------------------------


void __fastcall TFrmSplash::editDateKeyPress(TObject *Sender, char &Key)
{
if(Key == '\r')
  cmdAuthentication->Click();	
}
//---------------------------------------------------------------------------


void __fastcall TFrmSplash::JvTimer1Timer(TObject *Sender)
{
 counter--;
 labelCounter->Caption = counter;
 Application->ProcessMessages();
 if(counter <= 0)
  cmdAuthentication->Click();
}
//---------------------------------------------------------------------------

