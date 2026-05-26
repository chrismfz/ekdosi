//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FMysqlSync.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"

#include <vector>
using namespace std;
TFrmMySqlSync *FrmMySqlSync;
//---------------------------------------------------------------------------
__fastcall TFrmMySqlSync::TFrmMySqlSync(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *registry = new RegAccess(this);
 sqlQry = WideString(Query->SQL->GetText());

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
 sqlConnection->Params->Add("LocaleCode=$0408");
 sqlConnection->Params->Add("Password="+registry->getAppParameterString("MySQLPassword"));
 sqlConnection->Params->Add("Compressed=True");
 sqlConnection->Params->Add("Encrypted=True");
}
//---------------------------------------------------------------------------
int TFrmMySqlSync::getRecordCount()
{
 TSQLQuery *query = new TSQLQuery(this);

 query->SQLConnection = sqlConnection;
 query->SQL->Clear();
// ShowMessage(Query->SQL->GetText());
 query->SQL->Add(AnsiString("SELECT COUNT(*) as cnt FROM (")+AnsiString(WideString(Query->SQL->GetText()))+AnsiString(") A"));
 query->Active = true;

 int count = query->FieldByName("cnt")->AsInteger;
 delete query;

 return(count);
}

void TFrmMySqlSync::fetchData ()
{
 stopFlag = false;
 int numrows = 50;

 lblState->Caption = "Σύνδεση με την βάση δεδομένων. Παρακαλώ περιμένετε...";
 sqlConnection->Close();
 sqlConnection->Open();

 defaultVatCatId = getDefaultVatId();
 Query->SQLConnection = sqlConnection;
  try
 {
 // sqlConnection->Open();
  lblState->Caption = "Επιτυχής σύνδεση. Ανάγνωση δεδομένων...";
  Application->ProcessMessages();
 }
 catch (Exception &e)
 {
  showMessage(AnsiString("Η σύνδεση απέτυχε!\n"+AnsiString(e.Message)).c_str(),ApplicationName, MB_ICONERROR);
  lblState->Caption = "Η σύνδεση απέτυχε!";
  return;
 }

 int recordCount;
 Query->SQL->Clear();
 AnsiString sqlLimit = "LIMIT "+AnsiString(numrows);
 Query->SQL->Add(sqlQry);
 Query->SQL->Add(sqlLimit);
 recordCount = getRecordCount();
 
 while(recordCount > 0)
 {
  Query->Open();
  Application->ProcessMessages();
  progress->Maximum = recordCount;
  vector<int> productIdBasket;

  for(int i=0;i<recordCount;i++)
  {
   try{
   lblShow->Caption = "Εισαγωγή: "+AnsiString(i+1)+"/"+AnsiString(recordCount)+" ID:"+Query->FieldByName("PRODUCT_ID")->AsInteger;
   }catch(Exception &e)
   {
	break;
   }

   progress->Position = i;
   Application->ProcessMessages();
   checkFirebird(); //add record or update
   productIdBasket.push_back(Query->FieldByName("PRODUCT_ID")->AsInteger);
   Query->Next();

   Application->ProcessMessages();
  }

  changeState(productIdBasket);

  transaction->Commit();
  transaction->StartTransaction();
  
  if(stopFlag)
  {
   lblState->Caption = "Η διαδικασία ολοκληρώθηκε.";
   Query->Close();
   Query->SQL->Clear();
   sqlConnection->Close();
   return;
  }

  Query->Close();
  Query->SQL->Clear();
  AnsiString sqlLimit = "LIMIT "+AnsiString(numrows);
  Query->SQL->Add(sqlQry);
  Query->SQL->Add(sqlLimit);
  recordCount = getRecordCount();
 }
 
 lblState->Caption = "Η διαδικασία ολοκληρώθηκε.";

}

//---------------------------------------------------------------------------


void TFrmMySqlSync::checkFirebird()
{
 TIBQuery *query = new TIBQuery(this);

 query->Database = database;
 query->SQL->Add("SELECT PRODUCT_ID FROM PRODUCT WHERE PRODUCT_ID = :PRODUCT_ID");
 query->ParamByName("PRODUCT_ID")->AsInteger = Query->FieldByName("product_id")->AsInteger;

 query->Open();

 query->Last();
 int recordCount = query->RecordCount;
 query->First();
 delete query;

 if(recordCount == 0)//add new product
 {
  QueryInsert->ParamByName("PRODUCT_ID")->AsInteger = Query->FieldByName("PRODUCT_ID")->AsInteger;
  QueryInsert->ParamByName("BARCODE")->AsString = Query->FieldByName("PRODUCT_CODE")->AsString;
  QueryInsert->ParamByName("DESCRIPTION_SHORT")->AsString = Query->FieldByName("PRODUCT")->AsString;
  QueryInsert->ParamByName("CAT_ID")->AsInteger = 1;
  QueryInsert->ParamByName("VATCAT_ID")->AsInteger = defaultVatCatId;
  QueryInsert->ParamByName("METRIC_ID")->AsInteger = 6;
  QueryInsert->ParamByName("RESERVE")->AsInteger = Query->FieldByName("AMOUNT")->AsInteger;
  QueryInsert->ParamByName("PRICE_WVAT")->AsCurrency = Query->FieldByName("PRICE")->AsCurrency;
  QueryInsert->ExecSQL();
 }
 else //update product
 {
  QueryUpdate->ParamByName("PRODUCT_ID")->AsInteger = Query->FieldByName("PRODUCT_ID")->AsInteger;
  QueryUpdate->ParamByName("BARCODE")->AsString = Query->FieldByName("PRODUCT_CODE")->AsString;
  QueryUpdate->ParamByName("DESCRIPTION_SHORT")->AsString = Query->FieldByName("PRODUCT")->AsString;
  QueryUpdate->ParamByName("PRICEWVAT")->AsCurrency = Query->FieldByName("PRICE")->AsCurrency;
  QueryUpdate->ParamByName("SELL_PRICE")->AsCurrency = Query->FieldByName("PRICE")->AsCurrency;
  QueryUpdate->ParamByName("RESERVE")->AsInteger = Query->FieldByName("AMOUNT")->AsInteger;
  QueryUpdate->ExecSQL();
 }

}

int TFrmMySqlSync::getDefaultVatId()
{
 TIBQuery *qry = new TIBQuery(this);
 qry->Database = database;
 
 qry->SQL->Add("SELECT VATCAT_ID FROM VAT_CATEGORY WHERE DEFAULT_CAT = 1 ");
 qry->Open();

 qry->Last();
 int recordCount = qry->RecordCount;
 qry->First();

 if(recordCount == 0)
 {
  delete qry;
  return(-1);
 }

  int vatId;
  vatId = qry->FieldByName("VATCAT_ID")->AsInteger;

 delete qry;
 return(vatId);
}

void TFrmMySqlSync::changeState(vector<int> productId)
{
 vector<int>::iterator it;

 it = productId.begin();
 int i=0;
 progress->Position = 0;
 
 while(it != productId.end())
 {
  lblShow->Caption = "Ενημέρωση: "+AnsiString(i+1)+"/"+AnsiString(productId.size())+" ID:"+AnsiString(*it);
  i++;
  progress->Position = i;
  Application->ProcessMessages();
  AnsiString sql = "UPDATE cscart_products SET recordChanged = 0 WHERE product_id = "+AnsiString(*it)+";";
  bool flag = false;
  int retries = 0;
  while(!flag)
  {
   try{
	sqlConnection->Execute(sql,NULL,NULL);
//	sqlConnection->ExecuteDirect(sql);
	flag = true;
   }catch(Exception &e)
   {
	if(retries==10)
	{
	 showMessage("Δεν είναι δυνατή η αποκατάσταση της σύνδεσης!",ApplicationName,MB_ICONERROR);
	 break;
	}
	else
	{
	 retries++;
	 lblShow->Caption = "Προσπάθεια αποκατάστασης σύνδεσης...";
	}
   }//catch

   if(stopFlag)
	return;

  }//while

  if(it != productId.end()) //probably right
   it++;
 }
// lblShow->Caption = "Ενημέρωση: "+AnsiString(i)+"/"+AnsiString(productId.size())+" ID:"+AnsiString(*it);

}


void __fastcall TFrmMySqlSync::cmdAuthenticationClick(TObject *Sender)
{
 stopFlag = true;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmMySqlSync::JvDotNetButton1Click(TObject *Sender)
{
 fetchData();	
}
//---------------------------------------------------------------------------


