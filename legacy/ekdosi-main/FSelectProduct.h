//---------------------------------------------------------------------------

#ifndef FSelectProductH
#define FSelectProductH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvDotNetControls.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include "JvRadioButton.hpp"
#include <DB.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
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

#include "CNewSpecialForm.h"

using namespace std;

typedef void (__closure *ptrSetPrId)(int);
//---------------------------------------------------------------------------
class TFrmSelectProduct : public NewSpecialForm
{
__published:	// IDE-managed Components
	TImageList *ImageList1;
	TIBQuery *QryProduct;
	TDataSource *DSProducts;
	TJvPanel *PanelMain;
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TIntegerField *QryProductPRODUCT_ID;
	TIBStringField *QryProductBARCODE;
	TIBStringField *QryProductDESCRIPTION_SHORT;
	TIntegerField *QryProductCAT_ID;
	TIntegerField *QryProductVATCAT_ID;
	TIBBCDField *QryProductBUY_PRICE;
	TIBBCDField *QryProductSELL_PRICE;
	TIBBCDField *QryProductPRICE_WVAT;
	TDateField *QryProductDATE_INSERTED;
	TMemoField *QryProductDESCRIPTION;
	TIBStringField *QryProductCATEGORY;
	TIBBCDField *QryProductRESERVE;
	TIBBCDField *QryProductRESERVE_SECURE;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridProduct;
	TcxGridDBTableView *ViewProduct;
	TcxGridDBColumn *ViewProductPRODUCT_ID;
	TcxGridDBColumn *ViewProductBARCODE;
	TcxGridDBColumn *ViewProductDESCRIPTION_SHORT;
	TcxGridDBColumn *ViewProductPRCAT_DESCR;
	TcxGridDBColumn *ViewProductSELL_PRICE;
	TcxGridDBColumn *ViewProductPRICE_WVAT;
	TcxGridDBColumn *ViewProductRESERVE;
	TcxGridLevel *GridProductLevel1;
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall FormKeyDown(TObject *Sender, WORD &Key, TShiftState Shift);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall ViewProductCellDblClick(TcxCustomGridTableView *Sender, TcxGridTableDataCellViewInfo *ACellViewInfo,
          TMouseButton AButton, TShiftState AShift,
          bool &AHandled);
private:	// User declarations
	vector<EditBox *>  editBoxes;
	vector<EditBox *> garbage;
	TStringList *defaultSQL;
	NewSpecialForm *motherForm;
	ptrSetPrId ptrSetProductId;
	void __fastcall btnMinusClick(TObject *Sender);
	void __fastcall editSearchChange(TObject *Sender);
	void __fastcall searchBoxKeyPress(TObject *Sender, wchar_t &Key);
	void selectItem();
public:		// User declarations
	__fastcall TFrmSelectProduct(TComponent* Owner, NewSpecialForm *mother, ptrSetPrId ptr ,AnsiString tmpName, AnsiString tmpPrCode);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmSelectProduct *FrmSelectProduct;
//---------------------------------------------------------------------------
#endif
