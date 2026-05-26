//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FSelectCustomer.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
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
TFrmSelectCustomer *FrmSelectCustomer;
//---------------------------------------------------------------------------
__fastcall TFrmSelectCustomer::TFrmSelectCustomer(TComponent* Owner, NewSpecialForm *mother,ptrSetCustId ptr,AnsiString tmpName, AnsiString tmpVatNo)
	: NewSpecialForm(Owner)
{
 btnAdd->Left = PanelSearch->Width - 2 - btnAdd->Width;

 ptrSetCustomerId = ptr;
 
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 QryCustomer->Active = true;

 defaultSQL = new TStringList();

 defaultSQL->AddStrings(QryCustomer->SQL);

 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 0);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setKeyPressEvent(searchBoxKeyPress);
 box->setField("NAME", "Επωνυμία:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 if(tmpName.Trim().Length() > 0) //if Name text is written search with this crit
  box->setText(tmpName);

 if(tmpVatNo.Trim().Length() > 0)//add VαtNo searc field
 {
  EditBox *boxVatNo = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 0);

  boxVatNo->setClickEvent(btnMinusClick);
  boxVatNo->setTextChangedEvent(editSearchChange);
  boxVatNo->setKeyPressEvent(searchBoxKeyPress);
  boxVatNo->setField("AFM", "ΑΦΜ:");
  boxVatNo->setWidth(PanelSearch->Width);
  editBoxes.push_back(boxVatNo);

 boxVatNo->setText(tmpVatNo);
 }

 PanelSearch->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;


 motherForm = mother;
 buttonRollback->OnClick = this->cancelButton;
 delete reg;
}
//---------------------------------------------------------------------------

void TFrmSelectCustomer::setMotherForm(NewSpecialForm *_frm)
{
	motherForm = _frm;
}

void __fastcall TFrmSelectCustomer::btnMinusClick(TObject *Sender)
{
 unsigned int pos = ((((TWinControl *)Sender)->Top -SEARCH_VERTL_SPC) / SEARCH_SPC_BTWN);
 bool refreshDataset = false;

 vector<EditBox *>::iterator w;
 w = editBoxes.begin();
 
 for(int i=0;i < pos;i++)
   w++;

 if((*w)->getFilter().Length() > 0)
  refreshDataset = true;

 EditBox *tmpBox = *w;
 tmpBox->Hide();
 editBoxes.erase(w);
 garbage.push_back(tmpBox);

 if(refreshDataset)
  editSearchChange(Sender);

 if(editBoxes.size() == 0)
  return;

 w = editBoxes.begin();
 unsigned int i = 0;
 while( w != editBoxes.end())
 {
  (*w)->setTop((i * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC);
  i++;
  w++;
 }

 PanelSearch->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8; //was PanelTop
}

void __fastcall TFrmSelectCustomer::editSearchChange(TObject *Sender)
{
 vector<EditBox *>::iterator w;

 TStringList *OrderByList = new TStringList();

 OrderByList->Add(QryCustomer->SQL->Strings[QryCustomer->SQL->Count-2]);
 OrderByList->Add(QryCustomer->SQL->Strings[QryCustomer->SQL->Count-1]);

 QryCustomer->SQL->Clear();
 for(int i =0;i<defaultSQL->Count-2;i++)
  QryCustomer->SQL->Add(defaultSQL->Strings[i]);
 
 w = editBoxes.begin();
 QryCustomer->SQL->Add("WHERE");
 while( w != editBoxes.end() )
 {
  if((*w)->getFilter().Length() > 0)
   QryCustomer->SQL->Add((*w)->getFilter());
  else
  {
   w++;
   continue;
  }
  w++;
  if(w != editBoxes.end())
   QryCustomer->SQL->Add("AND");
 }


 if(QryCustomer->SQL->Count > 0 &&
			(QryCustomer->SQL->Strings[QryCustomer->SQL->Count-1] == AnsiString("WHERE") ||
			QryCustomer->SQL->Strings[QryCustomer->SQL->Count-1] == "AND"))
 QryCustomer->SQL->Delete(QryCustomer->SQL->Count-1);

 QryCustomer->SQL->Add(OrderByList->Strings[0]);
 QryCustomer->SQL->Add(OrderByList->Strings[1]);
 //debuging
// Memo1->Lines = QryCustomer->SQL;
 QryCustomer->Active = false;
 QryCustomer->Active = true;

 delete OrderByList;
}

void __fastcall TFrmSelectCustomer::cancelButton(TObject *Sender)
{
 Close();
}

void __fastcall TFrmSelectCustomer::btnAddClick(TObject *Sender)
{
 PanelSearchCrit->Left = btnAdd->Left;
 PanelSearchCrit->Top = btnAdd->Top + btnAdd->Height + 3;

 PanelSearchCrit->Left =  (btnAdd->Left + btnAdd->Width) - PanelSearchCrit->Width;



 PanelSearchCrit->Visible = true;
 btnAdd->Down = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectCustomer::JvDotNetButton1Click(TObject *Sender)
{
 AnsiString fieldName, fieldCaption;
 if(Radio1->Checked == true)
 {
  fieldName = "OCCUPATION";
  fieldCaption = "Δρ/τητα:";
 }
 else if (Radio2->Checked == true) 
 {
  fieldName = "NAME";
  fieldCaption = "Επωνυμία:";	   
 }
 else if (Radio3->Checked == true) 
 {
  fieldName = "AFM";
  fieldCaption = "ΑΦΜ:";	   
 }
 else if(Radio4->Checked == true)
 {
  fieldName = "CITY";
  fieldCaption = "Πόλη:";
 }
 else if(Radio5->Checked == true)
 {
  fieldName = "ADDRESS1 ADDRESS2 CITY";
  fieldCaption = "Διεύθυνση:";
 }
 else if(Radio6->Checked == true)
 {
  fieldName = "PHONE1 PHONE2 FAX";
  fieldCaption = "Τηλεφωνο:";
 }
 
 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 0);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setKeyPressEvent(searchBoxKeyPress);
 box->setField(fieldName, fieldCaption);
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 PanelSearch->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;

 PanelSearchCrit->Visible = false;
 btnAdd->Down = false;
	
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectCustomer::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
 vector<EditBox *>::iterator w;
 w = garbage.begin();

 while( w != garbage.end() )
 {
  delete (*w);
  w++;
 }

 w = editBoxes.begin();
 while( w != editBoxes.end() )
 {
  delete (*w);
  w++;
 }

 delete defaultSQL,editBoxes, garbage;
 motherForm->Enabled = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectCustomer::GridCustomersUserSort(
      TJvDBUltimGrid *Sender, TSortFields &FieldsToSort, AnsiString SortString,
      bool &SortOK)
{
for(int i=1;i<=SortString.Length();i++)
  if(SortString[i]=='[' || SortString[i]==']')
   SortString[i]=' ';

// How exceptions are written -- this is for joined field
/* if(SortString.Trim().SubString(1,8)=="USERNAME")
  SortString = "CONTRACT."+SortString.Trim();
 else
  SortString = "DOMAINS."+SortString.Trim();
*/
 try
 {
  QryCustomer->SQL->Strings[QryCustomer->SQL->Count - 1] = SortString;
  QryCustomer->Active = true;
 }catch(Exception &e)
 {
  showMessage(AnsiString(e.Message).c_str(),ApplicationName,MB_ICONERROR);
  return;
 }
 
 SortOK=true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectCustomer::FormKeyDown(TObject *Sender, WORD &Key,
      TShiftState Shift)
{
 if(Key == 40)
 {
  QryCustomer->Next();
  Key = 0;
 }
 else if(Key == 38)
 {
  QryCustomer->Prior();
  Key = 0;
 }
 else if(Key == 13)
 {
  Key = 0;
  sendId();
 }
}
//---------------------------------------------------------------------------
void __fastcall TFrmSelectCustomer::searchBoxKeyPress(TObject *Sender, wchar_t &Key)
{
 if(Key == '\r')
 {
  Key = 0;
 }
}
//---------------------------------------------------------------------------


void __fastcall TFrmSelectCustomer::ViewCustomersCellDblClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled)
{
 sendId();
}
//---------------------------------------------------------------------------

void TFrmSelectCustomer::sendId()
{
  Application->ProcessMessages();
 (ptrSetCustomerId)(QryCustomer->FieldByName("CUST_ID")->AsInteger);
 motherForm->Enabled = true;
 Close();
}
