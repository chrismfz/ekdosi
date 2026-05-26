//---------------------------------------------------------------------------
#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "FInvoiceSend.h"
#include "FPrint.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvExExtCtrls"
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
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
TFrmInvoiceSend *FrmInvoiceSend;
//---------------------------------------------------------------------------
__fastcall TFrmInvoiceSend::TFrmInvoiceSend(TComponent* Owner)
	: NewSpecialForm(Owner)
{

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();
// transaction->DefaultAction =  TARollback; //Rollback remaining invoice lines
 
 //set main dataset
 dataset = DatasetProductQty;
 dataset->Database = database;
 dataset->Transaction = transaction;

 editDate->Date = runningDate;

 dataset->ParamByName("INVOICEID")->IsNull = true;
 dataset->Open();
// loadInvoice();

}
//---------------------------------------------------------------------------

void TFrmInvoiceSend::fillProducts()
{
 /*TIBQuery *queryProducts = new TIBQuery(this);
 TIBQuery *queryInsertLine = new TIBQuery(this);

 queryProducts->Database= database;
 queryProducts->Transaction = transaction;
 queryInsertLine->Database= database;
 queryInsertLine->Transaction = transaction;

 queryProducts->SQL->Add("SELECT * FROM PRODUCT");
 queryInsertLine->SQL->Add("INSERT INTO INVLINES(INVLINE_ID, INVOICE_ID, QTY) VALUES(0, :INVOICEID, 0);");
 queryProducts->Active = true;
 queryProducts->Last();
 int recordCount = queryProducts->RecordCount;
 queryProducts->First();

 for(int i=0;i<recordCount;i++)
 {
  queryInsertLine->ParamByName("INVOICEID")->AsInteger = queryProducts->FieldByName("PRODUCT_ID")->AsInteger;
  queryInsertLine->ExecSQL();
  queryProducts->Next();
 }

 delete queryProducts, queryInsertLine;   */
}

void TFrmInvoiceSend::genInvoiceId()
{
 TIBQuery *tmpQuery = new TIBQuery(this);
 tmpQuery->SQL->Add("SELECT GEN_ID(GEN_INVOICE_ID,1) FROM RDB$DATABASE;");
 tmpQuery->Database = database;
 tmpQuery->Transaction = transaction;
 tmpQuery->Active = true;

 invoice_id = tmpQuery->FieldByName("GEN_ID")->AsInteger;

 delete tmpQuery;
}

void TFrmInvoiceSend::loadInvoice()
{
 dataset->Active = false;
 int invid = findCumInvoiceDate(editDate->Date);   //determine if there is already an CumulInvoice with this ID
 if(invid == 0 )
 {
  cmdAccept->Caption = "Καταχώρηση";
  cmdPrint->Visible = false;
  dataset->ParamByName("INVOICEID")->IsNull = true;
 }
 else
 {
  cmdAccept->Caption = "Αποδοχή";
  cmdPrint->Visible = true;
  dataset->ParamByName("INVOICEID")->AsInteger = invid;
 }
 dataset->Active = true;
}

int TFrmInvoiceSend::findCumInvoiceDate(TDate _date)
{
 return(0);

 //////CANCEL ALL
 TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Add("SELECT INVOICE_ID,INVTYPE,CONV_INVOICE_ID FROM INVOICE WHERE INVDATE = :INVDATE  ORDER BY INVOICE_ID DESC");
 query->ParamByName("INVDATE")->AsDate = _date;
 query->Active = true;

 query->Last();
 int recordNumber = query->RecordCount;
 query->First();
 ShowMessage(query->FieldByName("INVTYPE")->AsString);
 while((query->FieldByName("INVTYPE")->AsString != "ΣΔΑΠ" || !query->FieldByName("CONV_INVOICE_ID")->IsNull )  && query->RecNo < recordNumber)
 {
  if(query->FieldByName("INVTYPE")->AsString == "ΣΔΕΠ")
  {
   delete query;
	return(0);
  }
  query->Next();
 }

  int tmp;
  tmp = query->FieldByName("INVOICE_ID")->AsInteger;
  delete query;
   ShowMessage(tmp);
  return(tmp);
}

void __fastcall TFrmInvoiceSend::GridProductQtysDblClick(TObject *Sender)
{
/*
 if(dataset->State != dsEdit || dataset->State != dsInsert)
 {
  GridProductQtys->Options = GridProductQtys->Options >> dgRowSelect;
  GridProductQtys->Options = GridProductQtys->Options << dgEditing;
 }
 GridProductQtys->Col = 3;
 dataset->Edit();*/
}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::GridProductQtysMouseDown(TObject *Sender,
      TMouseButton Button, TShiftState Shift, int X, int Y)
{
// GridProductQtys->MouseToCell(X,Y, selCol, selRow);
}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::DatasetProductQtyAfterPost(TDataSet *DataSet)
{
// GridProductQtys->Options = GridProductQtys->Options >> dgEditing;
// GridProductQtys->Options = GridProductQtys->Options << dgRowSelect;
}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::JvDotNetButton2Click(TObject *Sender)
{
 transaction->Rollback();
 Close();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::cmdAcceptClick(TObject *Sender)
{
 if(QryInvoice->Active == true)
 {
  transaction->Commit();
  Close();
  return;
 }
 TIBQuery *queryInsert = new TIBQuery(this);

 queryInsert->Database = database;
 queryInsert->Transaction = transaction;
 queryInsert->SQL->Add("INSERT INTO INVOICE(INVOICE_ID, INVTYPE, INVDATE, PRINTED, CONV_INVOICE_ID, INVTIME) VALUES(:INVOICE_ID, \'ΣΔΑΠ\', :INVDATE, 0, :CONV_INVOICE_ID, :INVTIME);");

 queryInsert->ParamByName("INVDATE")->AsDate = editDate->Date;
 queryInsert->ParamByName("INVTIME")->AsTime = editTime->Time;
 genInvoiceId();
 queryInsert->ParamByName("INVOICE_ID")->AsInteger = invoice_id;

 if(cumInvoiceId != 0)
  queryInsert->ParamByName("CONV_INVOICE_ID")->AsInteger = cumInvoiceId;

 queryInsert->ExecSQL();

 queryInsert->SQL->Clear();
 queryInsert->SQL->Add("UPDATE INVLINES SET INVOICE_ID = :INVOICE_ID WHERE INVOICE_ID IS NULL;");
 queryInsert->ParamByName("INVOICE_ID")->AsInteger = invoice_id;
 queryInsert->ExecSQL();

 delete queryInsert;

 transaction->Commit();

 if( showMessage("Εκτύπωση; ",ApplicationName, MB_YESNO) == 6)
 {//print
  TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			getReportFilename(),
			"INVOICE_ID",
			invoice_id, true);
 }
 
 Close();
}
//---------------------------------------------------------------------------


void __fastcall TFrmInvoiceSend::editDateChange(TObject *Sender)
{
// loadInvoice();
 cumInvoiceId = findCumInvoiceDate(editDate->Date);
 if(cumInvoiceId != 0)
 {
  lblWarning->Caption = "Συμπληρωματικό δελτίο";
  lblWarning->Visible = true;
  JvPanel1->Height = 53;
 }
 else
 {
  lblWarning->Visible = false;
  JvPanel1->Height = 33;
 }

}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::GridProductQtysKeyDown(TObject *Sender,
      WORD &Key, TShiftState Shift)
{
/*
 if(Key >='0' && Key <='9')
 {
  if(dataset->State != dsEdit || dataset->State != dsInsert)
 {
  GridProductQtys->Options = GridProductQtys->Options >> dgRowSelect;
  GridProductQtys->Options = GridProductQtys->Options << dgEditing;
 }
 GridProductQtys->Col = 3;
 dataset->Edit();
 }*/
}
//---------------------------------------------------------------------------

void __fastcall TFrmInvoiceSend::GridProductQtysExit(TObject *Sender)
{
 if(dataset->State == dsEdit || dataset->State == dsInsert)
  dataset->Post();	
}
//---------------------------------------------------------------------------

AnsiString TFrmInvoiceSend::getReportFilename ()
{
TIBQuery *query = new TIBQuery(this);
 query->Database = database;
 query->Transaction = transaction;
 query->SQL->Text = "SELECT FRM_FILENAME FROM INVTYPE WHERE INVTYPE_ID = 'ΣΔΑΠ'";
 query->Open();
 AnsiString filename = query->FieldByName("FRM_FILENAME")->AsString;
 delete query;
 return(filename);
}
