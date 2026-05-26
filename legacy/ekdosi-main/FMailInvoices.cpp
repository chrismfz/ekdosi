//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMailInvoices.h"
#include "RegistryAccess.h"
#include "System.SysUtils.hpp"
#include "sys\types.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "dxSkinBlack"
#pragma link "dxSkinBlue"
#pragma link "dxSkinBlueprint"
#pragma link "dxSkinCaramel"
#pragma link "dxSkinCoffee"
#pragma link "dxSkinDarkRoom"
#pragma link "dxSkinDarkSide"
#pragma link "dxSkinDevExpressDarkStyle"
#pragma link "dxSkinDevExpressStyle"
#pragma link "dxSkinFoggy"
#pragma link "dxSkinGlassOceans"
#pragma link "dxSkinHighContrast"
#pragma link "dxSkiniMaginary"
#pragma link "dxSkinLilian"
#pragma link "dxSkinLiquidSky"
#pragma link "dxSkinLondonLiquidSky"
#pragma link "dxSkinMcSkin"
#pragma link "dxSkinMetropolis"
#pragma link "dxSkinMetropolisDark"
#pragma link "dxSkinMoneyTwins"
#pragma link "dxSkinOffice2007Black"
#pragma link "dxSkinOffice2007Blue"
#pragma link "dxSkinOffice2007Green"
#pragma link "dxSkinOffice2007Pink"
#pragma link "dxSkinOffice2007Silver"
#pragma link "dxSkinOffice2010Black"
#pragma link "dxSkinOffice2010Blue"
#pragma link "dxSkinOffice2010Silver"
#pragma link "dxSkinOffice2013DarkGray"
#pragma link "dxSkinOffice2013LightGray"
#pragma link "dxSkinOffice2013White"
#pragma link "dxSkinOffice2016Colorful"
#pragma link "dxSkinOffice2016Dark"
#pragma link "dxSkinPumpkin"
#pragma link "dxSkinsCore"
#pragma link "dxSkinsDefaultPainters"
#pragma link "dxSkinSeven"
#pragma link "dxSkinSevenClassic"
#pragma link "dxSkinSharp"
#pragma link "dxSkinSharpPlus"
#pragma link "dxSkinSilver"
#pragma link "dxSkinSpringTime"
#pragma link "dxSkinStardust"
#pragma link "dxSkinSummer2008"
#pragma link "dxSkinTheAsphaltWorld"
#pragma link "dxSkinTheBezier"
#pragma link "dxSkinValentine"
#pragma link "dxSkinVisualStudio2013Blue"
#pragma link "dxSkinVisualStudio2013Dark"
#pragma link "dxSkinVisualStudio2013Light"
#pragma link "dxSkinVS2010"
#pragma link "dxSkinWhiteprint"
#pragma link "dxSkinXmas2008Blue"
#pragma resource "*.dfm"
TFrmMailInvoices *FrmMailInvoices;
//---------------------------------------------------------------------------
__fastcall TFrmMailInvoices::TFrmMailInvoices(TComponent* Owner, int _invoiceId, bool _justCreated)
	: NewSpecialForm(Owner)
{
 invoiceId = _invoiceId;

 justCreated = _justCreated;

 abortSending = false;
	Width = 300;
	completionCode = NULL;
	completionMessage = NULL;
}
//---------------------------------------------------------------------------


bool TFrmMailInvoices::sendMail()
{
 TIdMessage *msg = new TIdMessage(this);
 msg->CharSet = "utf-8";
 msg->From->Address = "invoice@myip.gr";
 msg->From->Name = "MyIP net-Works Invoice System";
 msg->Sender->Address = "invoice@myip.gr";
 TIdEMailAddressItem *replyTo = msg->ReplyTo->Add();
 replyTo->Address = "support@myip.gr";
 TIdEMailAddressItem *addr = msg->Recipients->Add();
 TIdEMailAddressItem *addr2;
 addr->Address = recipient1;

 if(recipient2.Length() > 0 )
 {
  addr2 = msg->Recipients->Add();
  addr2->Address = recipient2;
 }

 TIdEMailAddressItem *bccMe = msg->BccList->Add();
 bccMe->Address = "invoice@myip.gr";

 msg->Subject = "Αποστολή παραστατικού "+invoiceCode;

 TStringList *strings = new TStringList();
 strings->Add("Αξιότιμε πελάτη,\n");
 strings->Add("Σας αποστέλλουμε το τιμολόγιο παροχής υπηρεσιών. Παρακαλούμε εκτυπώστε και προωθήστε το παραστατικό στο λογιστήριο σας.\n");
 strings->Add("Το παρόν μήνυμα είναι αυτοματοποιημένο. Εάν έχετε οποιαδήποτε απορία παρακαλούμε απαντήστε με reply σε αυτό το e-mail.\n");
 strings->Add("Invoicing System");
 strings->Add("MyIP net-Works");
 msg->Body = strings;

 //attachment

	if(!FileExists(invoiceFullPath))
	{
		if(completionCode != NULL)
		{
			*completionCode = -1;
			*completionMessage = "Invoice file not found!\n"+invoiceFullPath;
		}
		else
			showMessage("Invoice file not found!\n"+invoiceFullPath,MB_ICONERROR);
		delete msg, strings, bccMe, addr2, replyTo, addr;
		return(false);
 }

 TIdAttachment *attach = new TIdAttachmentFile(msg->MessageParts, invoiceFullPath);

 if(!abortSending)
 {
  try
  {
   smtp->Connect();
   smtp->Send(msg);
   smtp->Disconnect(true);
  }catch(Exception &e)
  {
			//ShowMessage(e.Message);
			if(completionCode != NULL)
			{
				*completionCode = -1;
    *completionMessage = "Exception during send out: "+e.Message;
			}
		}
	}
	else
	{
		if(completionMessage != NULL)
		{
			*completionCode = -1;
			*completionMessage = "Aborted sending mail. of "+invoiceFullPath;
		}
  delete msg, attach, strings, bccMe, addr2, replyTo, addr;
		Close();
	}

	setInvoiceSent(invoiceId);
 delete msg, attach, strings, bccMe, addr2, replyTo, addr;
 return(true);
}

void __fastcall TFrmMailInvoices::TimerTimer(TObject *Sender)
{
 Progress->Properties->Text = "Initializing..";
 setInvoiceMailParams();
 Timer->Enabled = false;

 if(recipient1.Length() == 0)
	{
		if(completionCode != NULL)
		{
				*completionCode = -1;
				*completionMessage = "No valid mail found for invoice: "+invoiceCode;
		}
		else
			showMessage("No valid mail found for invoice:"+invoiceCode,MB_ICONERROR);
		Timer->Enabled = false;
  abortSending = true;
  Close();
  return;
 }
 Sleep(150);
 Progress->Properties->Text = "Waiting file..";
 bool fileShowedUp = waitToShowUp();
 Application->ProcessMessages();

 if(abortSending)
 {
  Progress->Properties->Text = "Aborted!";
		Timer->Enabled = false;
  if(completionCode != NULL)
			{
				*completionCode = -1;
    *completionMessage = "Aborted sending "+invoiceCode;
			}
  Close();
  return;
 }

 Progress->Properties->Text = "Sending..";
 Application->ProcessMessages();

 if(!fileShowedUp)
	{
		if(completionCode != NULL)
			{
				*completionCode = -1;
    *completionMessage = "File did not show up! "+invoiceCode;
			}
  Progress->Properties->Text = "Failed!";
//  ShowMessage("Failed!");
 }
 else
 {
  Application->ProcessMessages();
		if(!abortSending && !sendMail())
		{
			if(completionCode != NULL)
			{
				*completionCode = -1;
    if(*completionMessage == "")
 				*completionMessage = "Failed sending "+invoiceCode;
			}
			Progress->Properties->Text = "Failed!";
		}
		else
		{
			Progress->Properties->Text = "Done!";
   if(completionCode != NULL)
			{
				*completionCode = 1;
    AnsiString allRcpt;
    allRcpt = "[ "+recipient1;
    if(recipient2.Length() > 0)
    {
     allRcpt = allRcpt + ", " + recipient2 + " ]";
    }
    else
    {
     allRcpt = allRcpt + " ]";
    }
				*completionMessage = "Successfully sent "+invoiceCode+" to: "+allRcpt;
			}
		}
 }

 Progress->Properties->Marquee = false;
 Application->ProcessMessages();
 Sleep(500);


 Timer->Enabled = false;
 Close();
}
//---------------------------------------------------------------------------

bool TFrmMailInvoices::waitToShowUp()
{
 time_t start = time(NULL);
 bool found = false;

 while(time(NULL) < (start+ 20) && !found && !abortSending)
 {
  if(FileExists(invoiceFullPath))
  {
   found = true;
   break;
  }
  Application->ProcessMessages();
  Sleep(300);
 }

 if(!found && abortSending == false)
 {
  showMessage("Timeout waiting for invoice file:"+AnsiString(invoiceFullPath),MB_ICONERROR);
  return(false);
 }
 return(true);
}

void TFrmMailInvoices::setInvoiceMailParams()
{
 TIBQuery *query = getNewQuery();
 time_t start = time(NULL);

 query->SQL->Text = "SELECT EMAIL, SECONDARY_EMAIL, INVCODE FROM INVOICE LEFT OUTER JOIN CUSTOMER \
						ON INVOICE.CUST_ID = CUSTOMER.CUST_ID WHERE INVOICE_ID = :INVOICE_ID";
 query->ParamByName("INVOICE_ID")->AsInteger = invoiceId;
 query->Open();

 while(query->FieldByName("INVCODE")->AsString == "" && time(NULL)  < (start+ 20))
 {
  Sleep(500);
  Application->ProcessMessages();
  query->Close();
  query->Open();
 }

 invoicePath = getPath();

 recipient1 = query->FieldByName("EMAIL")->AsString;
 recipient2 = query->FieldByName("SECONDARY_EMAIL")->AsString;

 invoiceCode = query->FieldByName("INVCODE")->AsString;
 invoiceFullPath = invoicePath + "\\"+invoiceCode+".pdf";

 delete query;
}

AnsiString TFrmMailInvoices::getPath()
{
	return(getConfString("PdfExportDir"));
}

void __fastcall TFrmMailInvoices::FormShow(TObject *Sender)
{
 this->Height = 26;
}
//---------------------------------------------------------------------------



void __fastcall TFrmMailInvoices::cmdAbortClick(TObject *Sender)
{
 int retVal;

 retVal = showMessage("Abort Sending?",MB_YESNO);

 if(retVal == 6)
 {
  abortSending = true;
 }

 cmdAbort->Caption = "Aborting...";
 cmdAbort->Enabled = false;
}
//---------------------------------------------------------------------------

void TFrmMailInvoices::setCompletenessPtr(long *_completionCode, AnsiString *_completionMessage)
{
	completionCode = _completionCode;
 completionMessage = _completionMessage;
}

void TFrmMailInvoices::setInvoiceSent(long _invoiceId)
{
 auto_ptr<TIBQuery> query = getSmartNewQuery();

	query->SQL->Text = "UPDATE INVOICE SET MAILED = 1 WHERE INVOICE_ID = :INVOICE_ID;";
 query->ParamByName("INVOICE_ID")->AsInteger = _invoiceId;
	query->ExecSQL();
}
