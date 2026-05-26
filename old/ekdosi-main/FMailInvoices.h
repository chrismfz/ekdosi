//---------------------------------------------------------------------------

#ifndef FMailInvoicesH
#define FMailInvoicesH
//---------------------------------------------------------------------------
#include "CNewSpecialForm.h"
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "IdBaseComponent.hpp"
#include "IdComponent.hpp"
#include "IdExplicitTLSClientServerBase.hpp"
#include "IdMessageClient.hpp"
#include "IdSMTP.hpp"
#include "IdSMTPBase.hpp"
#include "IdAttachmentFile.hpp"
#include "IdTCPClient.hpp"
#include "IdTCPConnection.hpp"
#include "IdPOP3.hpp"
#include "cxButtons.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include <Vcl.Menus.hpp>
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxEdit.hpp"
#include "cxProgressBar.hpp"
#include "JvTimer.hpp"
#include <IdIOHandler.hpp>
#include <IdIOHandlerSocket.hpp>
#include <IdIOHandlerStack.hpp>
#include <IdSSL.hpp>
#include <IdSSLOpenSSL.hpp>
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"

//---------------------------------------------------------------------------
class TFrmMailInvoices : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIdSMTP *smtp;
	TcxButton *cmdSend;
	TcxProgressBar *Progress;
	TJvTimer *Timer;
	TcxButton *cmdAbort;
 TIdSSLIOHandlerSocketOpenSSL *IdSSLIOHandlerSocketOpenSSL1;
	void __fastcall TimerTimer(TObject *Sender);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall cmdAbortClick(TObject *Sender);
private:	// User declarations
	bool justCreated;
	int invoiceId;
	bool abortSending;
	long *completionCode;
 AnsiString *completionMessage;
	AnsiString invoiceFullPath;
	AnsiString invoicePath;
	AnsiString invoiceCode;
	AnsiString recipient1;
	AnsiString recipient2;
	void setInvoiceMailParams();
	AnsiString getPath();
	bool sendMail();
	bool waitToShowUp();
	void setInvoiceSent(long _invoiceId);
public:		// User declarations
	__fastcall TFrmMailInvoices(TComponent* Owner, int _invoiceId, bool _justCreated);
 void setCompletenessPtr(long *_completionCode, AnsiString *_completionMessage);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmMailInvoices *FrmMailInvoices;
//---------------------------------------------------------------------------
#endif
