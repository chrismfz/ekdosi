//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FShowDuplicates.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmShowDuplicates *FrmShowDuplicates;
//---------------------------------------------------------------------------
__fastcall TFrmShowDuplicates::TFrmShowDuplicates(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *registry = new RegAccess(this);

 registry->restorePanelParams(PanelMain);
 registry->getColors(&primary, &secondary, &selectedColor);
 GridDuplicates->Color = primary;
 GridDuplicates->AlternateRowColor = secondary;
 GridDetail->Color = primary;
 GridDetail->AlternateRowColor = secondary;


 //Grid Events
 GridDuplicates->OnDrawColumnCell = this->GridDrawColumnCell;
 GridDuplicates->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridDuplicates->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridDuplicates->OnTitleBtnClick = this->GridTitleBtnClick;
 GridDuplicates->OnUserSort = this->GridUserSort;

 GridDetail->OnDrawColumnCell = this->GridDrawColumnCell;
 GridDetail->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridDetail->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridDetail->OnTitleBtnClick = this->GridTitleBtnClick;
 GridDetail->OnUserSort = this->GridUserSort;

 if(!registry->getAppParameterInt("MySqlSyncEnabled"))
 {
  showMessage("Παρακαλώ θέστε τις σωστές παραμέτρους.",ApplicationName, MB_ICONERROR);
  delete registry;
  Close();
 }

 sqlConnection->Params->Clear();
 sqlConnection->Params->Add("DriverUnit=DBXDynalink");
 sqlConnection->Params->Add("DriverPackageLoader=TDBXDynalinkDriverLoader,DbxDynalinkDriver100.bpl");
 sqlConnection->Params->Add("DriverAssemblyLoader=Borland.Data.TDBXDynalinkDriverLoader,Borland.Data.DbxDynalinkDriver,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b");
 sqlConnection->Params->Add("MetaDataPackageLoader=TDBXMySqlMetaDataCommandFactory,DbxReadOnlyMetaData100.bpl");
 sqlConnection->Params->Add("MetaDataAssemblyLoader=Borland.Data.TDBXMySqlMetaDataCommandFactory,Borland.Data.DbxReadOnlyMetaData,Version=11.0.5000.0,Culture=neutral,PublicKeyToken=91d62ebb5b0d1b1b");
 sqlConnection->Params->Add("BlobSize=-1");
 sqlConnection->Params->Add("Database="+registry->getAppParameterString("MySQLDbName"));
 sqlConnection->Params->Add("User_Name="+registry->getAppParameterString("MySQLUsername"));
 sqlConnection->Params->Add("ErrorResourceFile=");
 sqlConnection->Params->Add("HostName="+registry->getAppParameterString("MySQLHostname"));
 sqlConnection->Params->Add("LocaleCode=0408");
 sqlConnection->Params->Add("Password="+registry->getAppParameterString("MySQLPassword"));
 sqlConnection->Params->Add("Compressed=True");
 sqlConnection->Params->Add("Encrypted=True");

 sqlConnection->Close();
 sqlConnection->Open();

 DatasetDups->Open();
 delete registry;
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
  RegAccess *registry = new RegAccess(this);
  registry->savePanelParams(PanelMain);

  delete registry;
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::DatasetProductBeforeOpen(TDataSet *DataSet)
{
 DatasetProduct->DataSet->ParamByName("PRODUCT_CODE")->AsString = DatasetDups->FieldByName("PRODUCT_CODE")->AsString;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::DatasetDupsAfterScroll(TDataSet *DataSet)
{
 DatasetProduct->Close();
 DatasetProduct->Open();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::ToolPreviousClick(TObject *Sender)
{
 DatasetDups->Prior();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::ToolNextClick(TObject *Sender)
{
 DatasetDups->Next();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowDuplicates::ToolRefreshClick(TObject *Sender)
{
 TByteDynArray bookmark;
 bookmark = DatasetDups->GetBookmark();
 try
 {
  DatasetDups->Active = false;
  DatasetDups->Active = true;
 }catch(Exception &e)
 {
  ;
 }

 DatasetDups->GotoBookmark(bookmark);	
}
//---------------------------------------------------------------------------
