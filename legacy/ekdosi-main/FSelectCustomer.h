//---------------------------------------------------------------------------

#ifndef FSelectCustomerH
#define FSelectCustomerH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <DB.hpp>
#include "JvDotNetControls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvRadioButton.hpp"
#include "cxClasses.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxEdit.hpp"
#include "cxFilter.hpp"
#include "cxGraphics.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxStyles.hpp"
#include "cxNavigator.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include <System.ImageList.hpp>
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"

#include <vector>
#include "CEditBox.h"

using namespace std;

#include "CNewSpecialForm.h"

typedef void (__closure *ptrSetCustId)(int);
//---------------------------------------------------------------------------
class TFrmSelectCustomer : public  NewSpecialForm
{
__published:	// IDE-managed Components
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TJvPanel *PanelMain;
	TImageList *ImageList1;
	TDataSource *DSCustomers;
	TIBQuery *QryCustomer;
	TIntegerField *QryCustomerCUST_ID;
	TIBStringField *QryCustomerAFM;
	TIBStringField *QryCustomerNAME;
	TIBStringField *QryCustomerADDRESS1;
	TIBStringField *QryCustomerADDRESS2;
	TIBStringField *QryCustomerCITY;
	TIBStringField *QryCustomerPOSTCODE;
	TIBStringField *QryCustomerFAX;
	TIBStringField *QryCustomerOCCUPATION;
	TIBStringField *QryCustomerTAXOFFICE;
	TMemoField *QryCustomerDETAILS;
	TJvPanel *PanelSearchCrit;
	TJvRadioButton *Radio1;
	TJvRadioButton *Radio2;
	TJvRadioButton *Radio3;
	TJvDotNetButton *JvDotNetButton1;
	TJvRadioButton *Radio4;
	TJvRadioButton *Radio6;
	TJvRadioButton *Radio5;
	TIBBCDField *QryCustomerDISCOUNT;
	TIBStringField *QryCustomerEMAIL;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridCustomers;
	TcxGridDBTableView *ViewCustomers;
	TcxGridLevel *GridCustomersLevel1;
	TcxGridDBColumn *ViewCustomersCUST_ID;
	TcxGridDBColumn *ViewCustomersAFM;
	TcxGridDBColumn *ViewCustomersNAME;
	TcxGridDBColumn *ViewCustomersADDRESS1;
	TcxGridDBColumn *ViewCustomersADDRESS2;
	TcxGridDBColumn *ViewCustomersCITY;
	TcxGridDBColumn *ViewCustomersPOSTCODE;
	TcxGridDBColumn *ViewCustomersPHONE1;
	TcxGridDBColumn *ViewCustomersPHONE2;
	TcxGridDBColumn *ViewCustomersFAX;
	TcxGridDBColumn *ViewCustomersOCCUPATION;
	TcxGridDBColumn *ViewCustomersTAXOFFICE;
	TcxGridDBColumn *ViewCustomersDETAILS;
	TcxGridDBColumn *ViewCustomersDISCOUNT;
	TcxGridDBColumn *ViewCustomersEMAIL;
	TIntegerField *QryCustomerALT_CUSTID;
	TIBStringField *QryCustomerPHONE1;
	TIBStringField *QryCustomerPHONE2;
	TIBStringField *QryCustomerSECONDARY_EMAIL;
	TIntegerField *QryCustomerORDER;
	TIBStringField *QryCustomerCOUNTRY;
	TIntegerField *QryCustomerPAYMETH_ID;
	TIBStringField *QryCustomerVAT_VIES;
	void __fastcall btnAddClick(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall GridCustomersUserSort(TJvDBUltimGrid *Sender,
		  TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
	void __fastcall FormKeyDown(TObject *Sender, WORD &Key, TShiftState Shift);
	void __fastcall searchBoxKeyPress(TObject *Sender, wchar_t &Key);
	void __fastcall ViewCustomersCellDblClick(TcxCustomGridTableView *Sender, TcxGridTableDataCellViewInfo *ACellViewInfo,
		  TMouseButton AButton, TShiftState AShift,
		  bool &AHandled);
private:	// User declarations
	vector<EditBox *>  editBoxes;
	vector<EditBox *> garbage;
	TStringList *defaultSQL;
	NewSpecialForm *motherForm;
	ptrSetCustId ptrSetCustomerId;
	void __fastcall cancelButton(TObject *Sender);
	void __fastcall btnMinusClick(TObject *Sender);
	void __fastcall editSearchChange(TObject *Sender);
	void sendId();
public:		// User declarations
	__fastcall TFrmSelectCustomer(TComponent* Owner,  NewSpecialForm *mother, ptrSetCustId ptr, AnsiString tmpName, AnsiString tmpVatNo);
	void setMotherForm(NewSpecialForm *_form);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmSelectCustomer *FrmSelectCustomer;
//---------------------------------------------------------------------------
#endif
