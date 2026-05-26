//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FSelectProduct.h"
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
TFrmSelectProduct *FrmSelectProduct;
//---------------------------------------------------------------------------
__fastcall TFrmSelectProduct::TFrmSelectProduct(TComponent* Owner, NewSpecialForm *mother, ptrSetPrId ptr ,AnsiString tmpName, AnsiString tmpPrCode)
	: NewSpecialForm(Owner)
{
 btnAdd->Left = PanelSearch->Width - 2 - btnAdd->Width;

 //WTF?!? -- SET FUNCTION TO BE CALLED!
 ptrSetProductId = ptr;

 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);

  StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 QryProduct->Database = database;
 QryProduct->Transaction = transaction;
 QryProduct->Active = true;

 defaultSQL = new TStringList();

 defaultSQL->AddStrings(QryProduct->SQL);

 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 0);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setKeyPressEvent(searchBoxKeyPress);
 box->setField("PRODUCT.DESCRIPTION_SHORT PRODUCT.DESCRIPTION_SHORT", "Περιγραφή:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 if(tmpName.Trim().Length() > 0) //if Name text is written search with this crit
  box->setText(tmpName);

 if(tmpPrCode.Trim().Length() > 0)//add VatNo search field
 {
  EditBox *boxPrId = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 0);

  boxPrId->setClickEvent(btnMinusClick);
  boxPrId->setTextChangedEvent(editSearchChange);
  boxPrId->setKeyPressEvent(searchBoxKeyPress);
  boxPrId->setField("BARCODE", "Barcode:");
  boxPrId->setWidth(PanelSearch->Width);
  editBoxes.push_back(boxPrId);

 boxPrId->setText(tmpPrCode);
 }

 PanelSearch->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;

 
 motherForm = mother;
 buttonRollback->OnClick = this->cancelButton;
 delete reg;
}
//---------------------------------------------------------------------------

void __fastcall TFrmSelectProduct::btnMinusClick(TObject *Sender)
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

void __fastcall TFrmSelectProduct::editSearchChange(TObject *Sender)
{
 vector<EditBox *>::iterator w;
 TIBQuery *query = QryProduct;

 TStringList *OrderByList = new TStringList();

 OrderByList->Add(query->SQL->Strings[query->SQL->Count-2]);
 OrderByList->Add(query->SQL->Strings[query->SQL->Count-1]);

 query->SQL->Clear();
 for(int i =0;i<defaultSQL->Count-2;i++)
  query->SQL->Add(defaultSQL->Strings[i]);
 
 w = editBoxes.begin();
 query->SQL->Add("WHERE");
 while( w != editBoxes.end() )
 {
  if((*w)->getFilter().Length() > 0)
   query->SQL->Add((*w)->getFilter());
  else
  {
   w++;
   continue;
  }
  w++;
  if(w != editBoxes.end())
   query->SQL->Add("AND");
 }


 if(query->SQL->Count > 0 &&
			(query->SQL->Strings[query->SQL->Count-1] == AnsiString("WHERE") ||
			query->SQL->Strings[query->SQL->Count-1] == "AND"))
 query->SQL->Delete(query->SQL->Count-1);

 query->SQL->Add(OrderByList->Strings[0]);
 query->SQL->Add(OrderByList->Strings[1]);
 //debuging
// Memo1->Lines = QryCustomer->SQL;
 query->Active = false;
 query->Active = true;

 delete OrderByList;
}

void __fastcall TFrmSelectProduct::searchBoxKeyPress(TObject *Sender, wchar_t &Key)
{
 if(Key == '\r')
 {
  Key = 0;
 }
}
void __fastcall TFrmSelectProduct::FormCloseQuery(TObject *Sender,
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

 motherForm->Enabled = true;		
}
//---------------------------------------------------------------------------


void __fastcall TFrmSelectProduct::FormKeyDown(TObject *Sender, WORD &Key,
      TShiftState Shift)
{
if(Key == 40)
 {
  QryProduct->Next();
  Key = 0;
 }
 else if(Key == 38)
 {
  QryProduct->Prior();
  Key = 0;
 }
 else if(Key == 13)
 {
  Key = 0;
  selectItem();
 }	
}
//---------------------------------------------------------------------------


void __fastcall TFrmSelectProduct::FormShow(TObject *Sender)
{
 editBoxes[editBoxes.size()-1]->setFocus();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmSelectProduct::ViewProductCellDblClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled)
{
 selectItem();
}
//---------------------------------------------------------------------------

void TFrmSelectProduct::selectItem()
{
 motherForm->Enabled = true;
 Application->ProcessMessages();
 (ptrSetProductId)(QryProduct->FieldByName("PRODUCT_ID")->AsInteger);
 Close();
}
