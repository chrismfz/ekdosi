//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FManageReports.h"
#include "FReportDesign.h"
#include "FPrint.h"
#include "FSelectCustomer.h"
#include "FSelectProduct.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageReports *FrmManageReports;
//---------------------------------------------------------------------------
__fastcall TFrmManageReports::TFrmManageReports(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 TJvDBUltimGrid *grid = GridReports;

 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 grid->Color = primary;
 grid->AlternateRowColor = secondary;

 GridParams->Color = primary;
 GridParams->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetReports;

 dataset->Active = true;
 DatasetRepParams->Active = true;


 grid->OnDrawColumnCell = this->GridDrawColumnCell;
 grid->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 grid->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 grid->OnTitleBtnClick = this->GridTitleBtnClick;
 grid->OnUserSort = this->GridUserSort;

 GridParams->OnDrawColumnCell = this->GridDrawColumnCell;
 GridParams->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridParams->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridParams->OnTitleBtnClick = this->GridTitleBtnClick;
 GridParams->OnUserSort = this->GridUserSort;
 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 PageControl->ActivePageIndex = 0;
 delete reg;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::DatasetReportsAfterScroll(TDataSet *DataSet)
{
 showRecordsFetched();
  
 editFilename->Text = DataSet->FieldByName("FILENAME")->AsString;

 if(DataSet->FieldByName("SHOW_ON_MENU")->AsInteger == 1)
  checkShowOnMenu->Checked = true;
 else
  checkShowOnMenu->Checked = false;

 DatasetRepParams->Close();
 DatasetRepParams->Open();

}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolAddClick(TObject *Sender)
{
 PageControl->ActivePageIndex = 0;
 dataset->Insert();
 dataset->FieldByName("REPORT_ID")->AsInteger = 0;
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editDescription->SetFocus();
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::ToolDeleteClick(TObject *Sender)
{
 if(dataset->RecordCount==0)
  return;

 try
 {
  dataset->Delete();
 }
 catch(EIBInterBaseError &e)
 {
  if(e.IBErrorCode == 335544466)
   showMessage("’λλες εγγραφές εξαρτώνται απο αυτή την εγγραφή!",ApplicationName,MB_ICONERROR);
  else
   showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }		
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::ToolEditClick(TObject *Sender)
{
 PageControl->ActivePageIndex = 0;
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editDescription->SetFocus();
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::ToolAcceptClick(TObject *Sender)
{
 GridReports->SetFocus();
 if( dataset->FieldByName("REPORT_ID")->AsString.Length() == 0)
 {
  showMessage("Συμπληρώστε το όνομα της παραμέτρου",ApplicationName, MB_ICONERROR);
  return;
 }

 dataset->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;

 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::DatasetReportsAfterEdit(TDataSet *DataSet)
{
 editFilename->ReadOnly = false;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::DatasetReportsAfterInsert(TDataSet *DataSet)
{
 editFilename->ReadOnly = false;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::DatasetReportsAfterPost(TDataSet *DataSet)
{
 editFilename->ReadOnly = true;
 checkShowOnMenu->ReadOnly = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::DatasetReportsBeforeEdit(TDataSet *DataSet)
{
 checkShowOnMenu->ReadOnly = false;		
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::DatasetReportsBeforeInsert(TDataSet *DataSet)
{
 checkShowOnMenu->ReadOnly = false;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);

 if(transaction->InTransaction)
  transaction->Commit();
// transaction->Commit();
 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  ((TFrmMain *)Owner)->createChildMenuReport();
  
 delete reg;		
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::editFilenameChange(TObject *Sender)
{
 if(dataset->State == dsEdit || dataset->State == dsInsert)
  dataset->FieldByName("FILENAME")->AsString = editFilename->FileName;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::checkShowOnMenuClick(TObject *Sender)
{
 if(dataset->State == dsEdit || dataset->State == dsInsert)
 {
  dataset->FieldByName("SHOW_ON_MENU")->AsInteger = checkShowOnMenu->Checked;
 }	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::FormShow(TObject *Sender)
{
 showRecordsFetched();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageReports::ToolPPreviousClick(TObject *Sender)
{
 DatasetRepParams->Prior();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPNextClick(TObject *Sender)
{
 DatasetRepParams->Next();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPAddClick(TObject *Sender)
{
 DatasetRepParams->Insert();
 DatasetRepParams->FieldByName("INPUTDATA_ID")->AsInteger = 0;
 PageControl->ActivePageIndex = 1;
 
 ToolPAccept->Enabled = true;
 ToolPCancel->Enabled = true;
 ToolPEdit->Enabled = false;
 ToolPAdd->Enabled = false;
 ToolPDelete->Enabled = false;

 editParamName->SetFocus();
 editVariable->ReadOnly = false;
 comboVariable->ReadOnly = false;
 RadioFixed->Enabled = true;
 RadioSelector->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::DatasetRepParamsBeforeOpen(TDataSet *DataSet)
{
 DatasetRepParams->ParamByName("REPORT_ID")->AsInteger = dataset->FieldByName("REPORT_ID")->AsInteger;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::RadioFixedClick(TObject *Sender)
{
 if(RadioFixed->Checked)
 {
  editVariable->Enabled = true;
  comboVariable->Enabled = false;
  comboVariable->ItemIndex = -1;
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::RadioSelectorClick(TObject *Sender)
{
if(RadioSelector->Checked)
 {
  comboVariable->Enabled = true;
  editVariable->Enabled = false;
  editVariable->Text = "";
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::DatasetRepParamsAfterScroll(
      TDataSet *DataSet)
{
 AnsiString val = DatasetRepParams->FieldByName("VAL")->AsString;
 if(val.Length() == 0)
  return;

 if(val.SubString(0,1) == "[" && val.SubString(val.Length(),1) == "]")  //Selector
 {
  RadioSelector->Enabled = true;
  RadioSelector->Checked = true;
  RadioFixed->Enabled = false;
  editVariable->Text = "";
  comboVariable->ItemIndex = comboVariable->Items->IndexOf(val.SubString(2,val.Length()-2)) ;
 }
 else
 {
  RadioFixed->Enabled = true;
  RadioFixed->Checked = true;
  RadioSelector->Enabled = false;
  editVariable->Text = val;
  comboVariable->ItemIndex = -1;
 }

 comboVariable->ReadOnly = true;
 editVariable->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPEditClick(TObject *Sender)
{
 PageControl->ActivePageIndex = 1;
 DatasetRepParams->Edit();
 RollDetail->Collapsed = false;
 ToolPAccept->Enabled = true;
 ToolPCancel->Enabled = true;
 ToolPEdit->Enabled = false;
 ToolPAdd->Enabled = false;
 ToolPDelete->Enabled = false;

 editParamName->SetFocus();

 editVariable->ReadOnly = false;
 comboVariable->ReadOnly = false;
 RadioFixed->Enabled = true;
 RadioSelector->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPDeleteClick(TObject *Sender)
{
 if(DatasetRepParams->RecordCount==0)
  return;

 try
 {
  DatasetRepParams->Delete();
 }
 catch(EIBInterBaseError &e)
 {
  if(e.IBErrorCode == 335544466)
   showMessage("’λλες εγγραφές εξαρτώνται απο αυτή την εγγραφή!",ApplicationName,MB_ICONERROR);
  else
   showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPAcceptClick(TObject *Sender)
{
 GridReports->SetFocus();
 if( DatasetRepParams->FieldByName("VARIABLE_NAME")->AsString.Length() == 0)
 {
  showMessage("Συμπληρώστε το όνομα της παραμέτρου",ApplicationName, MB_ICONERROR);
  return;
 }

 DatasetRepParams->FieldByName("REPORT_ID")->AsInteger = dataset->FieldByName("REPORT_ID")->AsInteger;
 if(RadioFixed->Checked)
  DatasetRepParams->FieldByName("VAL")->AsString = editVariable->Text;
 else if(RadioSelector->Checked)
  DatasetRepParams->FieldByName("VAL")->AsString = "["+comboVariable->Text+"]";

 DatasetRepParams->Post();
 ToolPAccept->Enabled = false;
 ToolPCancel->Enabled = false;
 ToolPAdd->Enabled = true;
 ToolPDelete->Enabled = true;
 ToolPEdit->Enabled = true;

 
 ToolPRefreshClick(Sender);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPRefreshClick(TObject *Sender)
{
TByteDynArray bookmark;
 bookmark = DatasetRepParams->GetBookmark();
 try
 {
  DatasetRepParams->Active = false;
  DatasetRepParams->Active = true;
 }catch(Exception &e)
 {
  ;
 }

 DatasetRepParams->GotoBookmark(bookmark);	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolPCancelClick(TObject *Sender)
{
 DatasetRepParams->Cancel();
 ToolPAccept->Enabled = false;
 ToolPCancel->Enabled = false;
 ToolPAdd->Enabled = true;
 ToolPDelete->Enabled = true;
 ToolPEdit->Enabled = true;
 comboVariable->ReadOnly = true;
 editVariable->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::DatasetRepParamsAfterPost(TDataSet *DataSet)
{
 comboVariable->ReadOnly = true;
 editVariable->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::DatasetRepParamsAfterCancel(
      TDataSet *DataSet)
{
 comboVariable->ReadOnly = true;
 editVariable->ReadOnly = true;
}
//---------------------------------------------------------------------------


void __fastcall TFrmManageReports::DatasetRepParamsAfterOpen(TDataSet *DataSet)
{
 editVariable->Text = "";
 comboVariable->ItemIndex = -1;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmManageReports::JvExpressButton1Click(TObject *Sender)
{
 if( FileExists(editFilename->FileName)  )
  TFrmReportDesign *frmDesign = new TFrmReportDesign(Owner, editFilename->Text);			
}
//---------------------------------------------------------------------------


void __fastcall TFrmManageReports::buttonRunReportClick(TObject *Sender)
{
 if(editFilename->Text.Length() == 0 || !FileExists(editFilename->FileName))
 {
  return;
 }

 TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction, dataset->FieldByName("REPORT_ID")->AsInteger);
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageReports::ToolButton1Click(TObject *Sender)
{
 buttonRunReport->Click();	
}
//---------------------------------------------------------------------------


