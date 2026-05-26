#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FDBParams.h"

#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)

#pragma link "cxCheckBox"
#pragma link "cxContainer"
#pragma link "cxControls"
#pragma link "cxEdit"
#pragma link "cxGraphics"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "cxDropDownEdit"
#pragma link "cxMaskEdit"
#pragma link "cxTextEdit"
#pragma resource "*.dfm"

#include <Printers.hpp>
using namespace Printers;
TFrmDBParams *FrmDBParams;


//---------------------------------------------------------------------------
__fastcall TFrmDBParams::TFrmDBParams(TComponent* Owner)
		: NewSpecialForm(Owner)
{
 TFrmMain *frmMain;

 frmMain = (TFrmMain *)Owner;

 //disable cancel button
// disableCancelButton();

 if(frmMain->isLocal().Length()!=0)
 {
  isLocal = true;
  SheetDatabase->TabVisible = false;
 }
 else
 {
  isLocal = false;
 }

 if(!SHOW_PDF_TAB)//constant
  TabBullZip->TabVisible = false;

if(!CSCART_SYNC)
 TabSheet3->TabVisible = false;
 TabParameters->TabIndex = 0;
}
//---------------------------------------------------------------------------
void __fastcall TFrmDBParams::FormCreate(TObject *Sender)
{
 RegAccess *registry = new RegAccess(this);

 registry->restoreFormPos();

 int reminderContracts, reminderDomains, reminderServers;
 
 AnsiString *hostname = new AnsiString();
 AnsiString *path = new AnsiString();
 AnsiString *username = new AnsiString();
 AnsiString *password = new AnsiString();

 editPrinter->Text = registry->getAppParameterString("SelectedInvoicePrinterString");
 while(editPrinter->Text == "")
 {
  registry->setAppParameterString("SelectedInvoicePrinterString","Default");
  editPrinter->Text = registry->getAppParameterString("SelectedInvoicePrinterString");
 }

 if(!isLocal)
 {
  registry->getDBParams(hostname,path,
                        username,password);

  EditHostname->Text = *hostname;
  EditPath->Text = *path;
  EditUsername->Text = *username;
  dbPassword = *password;
 }

 if(!registry->colorDataExists())
 {
  PanelPrimary->Color = static_cast<TColor>(PALETTERGB(OddCellsRed,OddCellsGreen,OddCellsBlue));
  PanelSecondary->Color = static_cast<TColor>(PALETTERGB(EvenCellsRed,EvenCellsGreen,EvenCellsBlue));
  PanelSelected->Color = static_cast<TColor>(PALETTERGB(SelCellsRed,SelCellsGreen,SelCellsBlue));
 }
 else
 {
  TColor primary, secondary, selected;

  registry->getColors(&primary, &secondary,&selected);
  PanelPrimary->Color = primary;
  PanelSecondary->Color = secondary;
  PanelSelected->Color = selected;
 }

 loadParameters(); //Load Various options

 delete registry,hostname,path,username,password;
}
//---------------------------------------------------------------------------
void __fastcall TFrmDBParams::FormClose(TObject *Sender,
      TCloseAction &Action)
{
 RegAccess *registry = new RegAccess(this);

 registry->WriteFormPos();

 delete registry;
 Action = caFree;
}
//---------------------------------------------------------------------------
void __fastcall TFrmDBParams::Button3Click(TObject *Sender)
{
 Close();       
}
//---------------------------------------------------------------------------
void __fastcall TFrmDBParams::cmdOkClick(TObject *Sender)
{
 TFrmMain *frmMain;
 RegAccess *registry = new RegAccess(this);
 AnsiString passwd;

 frmMain = (TFrmMain *)Owner;

 if(EditPassword->Text.Length() > 0)
  passwd = EditPassword->Text;

 if(!isLocal && passwd.Length() > 0)
  registry->saveDBParams(EditHostname->Text,EditPath->Text,
						EditUsername->Text,passwd);

 registry->setColors(PanelPrimary->Color, PanelSecondary->Color, PanelSelected->Color);
 delete registry;
 saveParameters(); //Save various parameters

 //Execute File->Connect command from the Mainmenu
 frmMain->executeConnect();

 Close();
}
//---------------------------------------------------------------------------
void __fastcall TFrmDBParams::cmdTestClick(TObject *Sender)
{
 if(EditHostname->Text.Trim().Length()!=0)
  Database1->DatabaseName = EditHostname->Text+":"+EditPath->Text;
 else
  Database1->DatabaseName = EditPath->Text;
 Database1->Params->Add(AnsiString("user_name=")+EditUsername->Text);
 Database1->Params->Add(AnsiString("password=")+EditPassword->Text);

 try
 {
  Database1->Connected=true;
  if(Database1->TestConnected())
   showMessage("Η σύνδεση πέτυχε!",ApplicationName, MB_OK);
 }
 catch(Exception &e)
 {
  showMessage( "Η σύνδεση με την βάση απέτυχε!", ApplicationName, MB_ICONERROR );
 }

 Database1->Close();        
}
//---------------------------------------------------------------------------

void TFrmDBParams::loadParameters()
{
RegAccess *registry = new RegAccess(this);

 comboSelReserve->ItemIndex = registry->getAppParameterInt("Reserve");
 checkDotMatrix->Checked = registry->getAppParameterInt("DotMatrixPrinter");

// editDirPdfInstallation->Directory = registry->getAppParameterString("PdfInstallDir");
// editDirPdfSave->Directory = registry->getAppParameterString("PdfSaveDir");
// checkBullZip->Checked = registry->getAppParameterInt("BullZipEnabled");

 editDirPdfInstallation->Directory = getConfString("PdfInstallDir");
 editDirPdfSave->Directory = getConfString("PdfSaveDir");
 checkBullZip->Checked = getConfLong("BullZipEnabled");
 checkBullZipClick(checkBullZip);


 editMyHostname->Text = registry->getAppParameterString("MySQLHostname");
 editMyDbName->Text = registry->getAppParameterString("MySQLDbName");
 editMyUsername->Text = registry->getAppParameterString("MySQLUsername");
	editMyPassword->Text = registry->getAppParameterString("MySQLPassword");
	checkPdfExport->Checked = getConfLong("PdfExport") == 1 ? true : false;
	editDirExportPdf->Directory = getConfString("PdfExportDir");

	txtAadeUser->Text = getConfString("AadeUser");
	txtAadeKey->Text = getConfString("AadeKey");
	txtAfm->Text = getConfString("AFM");
	comboDevEnv->ItemIndex = getConfLong("ENABLE_DEV_ENV");

 checkCsCartSync->Checked = registry->getAppParameterInt("MySqlSyncEnabled");
 checkCsCartSyncClick(checkCsCartSync);

 if(registry->getAppParameterInt("GridPricesWVat")==1)
  comboGridPricesWVat->Text = "Ναι";
 else
  comboGridPricesWVat->Text = "Όχι";
 delete registry;

}

void TFrmDBParams::saveParameters()
{
 RegAccess *registry = new RegAccess(this);

 registry->setAppParameterInt("Reserve",comboSelReserve->ItemIndex);

 registry->setAppParameterInt("DotMatrixPrinter",checkDotMatrix->Checked);

// registry->setAppParameterString("PdfInstallDir",editDirPdfInstallation->Directory);
// registry->setAppParameterString("PdfSaveDir",editDirPdfSave->Directory);
 setConfString("PdfInstallDir", editDirPdfInstallation->Directory);
 setConfString("PdfSaveDir",editDirPdfSave->Directory);
	setConfLong("BullZipEnabled",checkBullZip->Checked);
// registry->setAppParameterInt("BullZipEnabled", checkBullZip->Checked);

	setConfString("AadeUser",txtAadeUser->Text);
	setConfString("AadeKey",txtAadeKey->Text);
	setConfString("AFM",txtAfm->Text);
	setConfLong("ENABLE_DEV_ENV", comboDevEnv->ItemIndex);

 registry->setAppParameterInt("MySqlSyncEnabled",checkCsCartSync->Checked);
 registry->setAppParameterString("MySQLHostname",editMyHostname->Text);
 registry->setAppParameterString("MySQLDbName", editMyDbName->Text);
 registry->setAppParameterString("MySQLUsername", editMyUsername->Text);
	registry->setAppParameterString("MySQLPassword", editMyPassword->Text);

	setConfLong("PdfExport", checkPdfExport->Checked ? 1 : 0);
	setConfString("PdfExportDir", editDirExportPdf->Directory);

 if(comboGridPricesWVat->Text == "Ναι")
  registry->setAppParameterInt("GridPricesWVat",1);
 else
  registry->setAppParameterInt("GridPricesWVat",0);
 delete registry;
}


void __fastcall TFrmDBParams::PanelMouseDown(TObject *Sender,
      TMouseButton Button, TShiftState Shift, int X, int Y)
{
 (( TJvPanel *)Sender)->BevelOuter = bvLowered;
}
//---------------------------------------------------------------------------

void __fastcall TFrmDBParams::PanelMouseUp(TObject *Sender,
      TMouseButton Button, TShiftState Shift, int X, int Y)
{
 (( TJvPanel *)Sender)->BevelOuter = bvRaised;
 DialogColor->Color = ((TJvPanel *)Sender)->Color;
  if(DialogColor->Execute())
   (( TJvPanel *)Sender)->Color = DialogColor->Color;
}
//---------------------------------------------------------------------------


void __fastcall TFrmDBParams::JvDotNetButton1Click(TObject *Sender)
{
 RegAccess *registry = new RegAccess(this);

 TPrinter *printer = Printer();
 printer->PrinterIndex = registry->getAppParameterInt("SelectedInvoicePrinter");

 PrinterDialog->Execute();

 registry->setAppParameterInt("SelectedInvoicePrinter",printer->PrinterIndex);

 registry->setAppParameterString("SelectedInvoicePrinterString",printer->Printers->Strings[printer->PrinterIndex]);

 editPrinter->Text = registry->getAppParameterString("SelectedInvoicePrinterString");

 delete registry;
}
//---------------------------------------------------------------------------


void __fastcall TFrmDBParams::checkBullZipClick(TObject *Sender)
{
 if(!checkBullZip->Checked)
 {
  editDirPdfInstallation->Enabled = false;
  editDirPdfSave->Enabled = false;
 }
 else
 {
  editDirPdfInstallation->Enabled = true;
  editDirPdfSave->Enabled = true;
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmDBParams::checkCsCartSyncClick(TObject *Sender)
{
 if(checkCsCartSync->Checked)
 {
  editMyHostname->Enabled = true;
  editMyDbName->Enabled = true;
  editMyUsername->Enabled = true;
  editMyPassword->Enabled = true;
  cmdMySqlTest->Enabled = true;
 }
 else
 {
  editMyHostname->Enabled = false;
  editMyDbName->Enabled = false;
  editMyUsername->Enabled = false;
  editMyPassword->Enabled = false;
  cmdMySqlTest->Enabled = false;
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmDBParams::cmdMySqlTestClick(TObject *Sender)
{
 sqlConnection->Params->Clear();

 sqlConnection->Params->Add("DriverUnit=DBXDynalink");
 sqlConnection->Params->Add("DriverPackageLoader=TDBXDynalinkDriverLoader,DbxDynalinkDriver100.bpl");
 sqlConnection->Params->Add("DriverAssemblyLoader=Borland.Data.TDBXDynalinkDriverLoader,Borland.Data.DbxDynalinkDriver,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b");
 sqlConnection->Params->Add("MetaDataPackageLoader=TDBXMySqlMetaDataCommandFactory,DbxReadOnlyMetaData100.bpl");
 sqlConnection->Params->Add("MetaDataAssemblyLoader=Borland.Data.TDBXMySqlMetaDataCommandFactory,Borland.Data.DbxReadOnlyMetaData,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b");
 sqlConnection->Params->Add("BlobSize=-1");
 sqlConnection->Params->Add("Database="+editMyDbName->Text);
 sqlConnection->Params->Add("User_Name="+editMyUsername->Text);
 sqlConnection->Params->Add("ErrorResourceFile=");
 sqlConnection->Params->Add("HostName="+editMyHostname->Text);
 sqlConnection->Params->Add("LocaleCode=$0408");
 sqlConnection->Params->Add("Password="+editMyPassword->Text);
 sqlConnection->Params->Add("Compressed=True");
 sqlConnection->Params->Add("Encrypted=True");

 try
 {
  sqlConnection->Open();
  ShowMessage("Επιτυχής σύνδεση!");
  sqlConnection->Close();
 }
 catch (Exception &e)
 {
  showMessage(AnsiString("Η σύνδεση απέτυχε!\n"+AnsiString(e.Message)).c_str(),ApplicationName, MB_ICONERROR);
 }


}
//---------------------------------------------------------------------------
  /*
  DriverUnit=DBXDynalink
DriverPackageLoader=TDBXDynalinkDriverLoader,DbxDynalinkDriver100.bpl
DriverAssemblyLoader=Borland.Data.TDBXDynalinkDriverLoader,Borland.Data.DbxDynalinkDriver,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b
 MetaDataPackageLoader=TDBXMySqlMetaDataCommandFactory,DbxReadOnlyMetaData100.bpl
 MetaDataAssemblyLoader=Borland.Data.TDBXMySqlMetaDataCommandFactory,Borland.Data.DbxReadOnlyMetaData,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b
BlobSize=-1
Database=DBNAME
ErrorResourceFile=
HostName=ServerName
LocaleCode=0000
Password=password
User_Name=user
Compressed=False
Encrypted=False*/

void TFrmDBParams::showMessage(AnsiString _message,AnsiString AppName, UINT btnType)
{
 Application->MessageBox(UnicodeString(_message).w_str(),UnicodeString(ApplicationName).w_str(),btnType);
}

void __fastcall TFrmDBParams::Action1Execute(TObject *Sender)
{
 ShowMessage(dbPassword);
}
//---------------------------------------------------------------------------

