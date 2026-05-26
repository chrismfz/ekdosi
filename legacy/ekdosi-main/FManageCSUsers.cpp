// ---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FManageCSUsers.h"
#include "FMain.h"
#include "RegistryAccess.h"
// ---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageCSUsers *FrmManageCSUsers;

// ---------------------------------------------------------------------------
__fastcall TFrmManageCSUsers::TFrmManageCSUsers(TComponent* Owner) : NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

// QueryClients->Open();
// tableProducts->Open();
// tableDomains->Open();
// QueryServers->Open();
 QueryPackages->Open();


 delete reg;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolEditClick(TObject *Sender)
{
// tableProducts->Edit();

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;

}
// ---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolAcceptClick(TObject *Sender)
{
// tableProducts->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;

 ToolEdit->Enabled = true;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolCancelClick(TObject *Sender)
{
// tableProducts->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolEdit->Enabled = true;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::cxTextEdit1PropertiesChange(TObject *Sender)

{
ViewCSCustomers->DataController->BeginUpdate();
 ViewCSCustomers->DataController->Filter->Root->Clear();
TcxFilterCriteriaItemList *itemList = ViewCSCustomers->DataController->Filter->Root->AddItemList(fboOr);
 itemList->AddItem(ViewCSCustomerFullname, foLike, "%"+editSearch->Text+"%", "%"+editSearch->Text+"%");
// itemList->AddItem(ViewCSCustomerslastname, foLike, "%"+editSearch->Text+"%", "%"+editSearch->Text+"%");
ViewCSCustomers->DataController->EndUpdate();
ViewCSCustomers->DataController->Filter->Active = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::cxButton2Click(TObject *Sender)
{
 editSearch->Text = "";
 ViewCSCustomers->DataController->BeginUpdate();
 ViewCSCustomers->DataController->Filter->Root->Clear();
 ViewCSCustomers->DataController->EndUpdate();
ViewCSCustomers->DataController->Filter->Active = true;

}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolRefreshClick(TObject *Sender)
{
 QueryClients->Close();
 QueryClients->Open();
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolEditDomainsClick(TObject *Sender)
{
// tableDomains->Edit();

 ToolAcceptDomains->Enabled = true;
 ToolCancelDomains->Enabled = true;
 ToolEditDomains->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolAcceptDomainsClick(TObject *Sender)
{
// tableDomains->Post();
 ToolAcceptDomains->Enabled = false;
 ToolCancelDomains->Enabled = false;

 ToolEditDomains->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::ToolCancelDomainsClick(TObject *Sender)
{
// tableDomains->Cancel();
 ToolAcceptDomains->Enabled = false;
 ToolCancelDomains->Enabled = false;
 ToolEditDomains->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCSUsers::QueryClientsCalcFields(TDataSet *DataSet)
{
  QueryClients->FieldByName("fullname")->AsString = QueryClients->FieldByName("firstname")->AsString +" "+QueryClients->FieldByName("lastname")->AsString;
}
//---------------------------------------------------------------------------

