//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FManageCSInvoices.h"
#include "FMain.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageCSInvoices *FrmManageCSInvoices;

//---------------------------------------------------------------------------
__fastcall TFrmManageCSInvoices::TFrmManageCSInvoices(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

// QueryInvoices->Open();
// QueryCustomers->Open();
// QueryCSInvoiceLines->Open();

 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageCSInvoices::QueryCustomersCalcFields(TDataSet *DataSet)

{
// QueryCustomers->FieldByName("FULLNAME")->AsString = QueryCustomers->FieldByName("firstname")->AsString +" "+  QueryCustomers->FieldByName("lastname")->AsString;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::editSearchPropertiesChange(TObject *Sender)

{
ViewCsInvoices->DataController->BeginUpdate();
 ViewCsInvoices->DataController->Filter->Root->Clear();
TcxFilterCriteriaItemList *itemList = ViewCsInvoices->DataController->Filter->Root->AddItemList(fboOr);
 itemList->AddItem(ViewCsInvoicesfullname, foLike, "%"+editSearch->Text+"%", "%"+editSearch->Text+"%");

ViewCsInvoices->DataController->EndUpdate();
ViewCsInvoices->DataController->Filter->Active = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::cxButton1Click(TObject *Sender)
{
 QueryInvoices->Close();

 QueryInvoices->SQL->Strings[2] = "";

 if(editSearch->Text.Trim().Length() > 0)
  QueryInvoices->SQL->Strings[2] = "where b.description like '%"+editSearch->Text.Trim()+"%'";

 QueryInvoices->Open();
}
//---------------------------------------------------------------------------


void __fastcall TFrmManageCSInvoices::ToolEditInvoicesClick(TObject *Sender)
{
 QueryInvoices->Edit();

 ToolAcceptInvoices->Enabled = true;
 ToolCancelInvoices->Enabled = true;
 ToolEditInvoices->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::ToolAcceptInvoicesClick(TObject *Sender)

{
QueryInvoices->Post();
 ToolAcceptInvoices->Enabled = false;
 ToolCancelInvoices->Enabled = false;

 ToolEditInvoices->Enabled = true;
}
//---------------------------------------------------------------------------



void __fastcall TFrmManageCSInvoices::cxButton2Click(TObject *Sender)
{
 QueryCSInvoiceLines->First();

// While(
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::ToolEditLinesClick(TObject *Sender)
{
QueryCSInvoiceLines->Edit();

 ToolAcceptLines->Enabled = true;
 ToolCancelLines->Enabled = true;
 ToolEditLines->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::ToolAcceptLinesClick(TObject *Sender)
{
 QueryCSInvoiceLines->Post();
 ToolAcceptLines->Enabled = false;
 ToolCancelLines->Enabled = false;

 ToolEditLines->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::ToolCancelInvoicesClick(TObject *Sender)
{
 QueryInvoices->Cancel();
 ToolAcceptInvoices->Enabled = false;
 ToolCancelInvoices->Enabled = false;
 ToolEditInvoices->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSInvoices::ToolCancelLinesClick(TObject *Sender)
{
 QueryCSInvoiceLines->Cancel();
 ToolAcceptLines->Enabled = false;
 ToolCancelLines->Enabled = false;
 ToolEditLines->Enabled = true;
}
//---------------------------------------------------------------------------

